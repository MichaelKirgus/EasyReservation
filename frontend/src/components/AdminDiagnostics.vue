<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue'
import { adminFetch } from '../utils/adminApi'

// Client-Zeit und Zeitzone
const clientTime = ref(new Date().toISOString())
const clientTimezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone)

let clientTimer = null
function updateClientTime() {
  clientTime.value = new Date().toISOString()
}
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'

// Worker-Status direkt aus diagnostics.queue.workers beziehen
const workerStatus = computed(() => diagnostics.value?.queue?.workers || [])
const workerLoading = ref(false) // bleibt für Kompatibilität, ist aber immer false
const workerColumns = [
  { key: 'worker_id', label: 'Worker-ID' },
  { key: 'ip', label: 'IP' },
  { key: 'timestamp', label: 'Letzter Heartbeat' },
  { key: 'memory', label: 'Speicher (MB)' },
  { key: 'redis_latency', label: 'Redis-Latenz (ms)' },
  { key: 'total_jobs', label: 'Jobs gesamt' },
  { key: 'last_job_time', label: 'Letzter Job (Zeitpunkt)' },
  { key: 'last_job_duration', label: 'Letzter Job (Dauer, ms)' },
  { key: 'jobs', label: 'Aktive Jobs' },
]



const apiKey = ref(localStorage.getItem('admin_api_key') || '')
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
  if (!apiKey.value) { setError('Bitte anmelden, API-Key fehlt.', opts); return }
  loading.value = true
  try {
    const res = await fetchWithAuth('diagnostics')
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    diagnostics.value = JSON.parse(text)
    localStorage.setItem('admin_api_key', apiKey.value)
    if (!opts.auto) setMessage('')
  } catch (e) {
    setError(`Fehler beim Laden: ${e}`, opts)
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

function startAutoRefresh() {
  stopAutoRefresh()
  if (!autoRefreshEnabled.value) return
  timerId = setInterval(() => {
    loadDiagnostics({ auto: true })
    loadAuditLogCount()
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
    loadWorkerStatus()
    loadAuditLogCount()
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
          <div class="value">{{ workerStatus.reduce((sum, w) => sum + (Array.isArray(w.jobs) ? w.jobs.length : (w.jobs && typeof w.jobs === 'object' ? Object.keys(w.jobs).length : 0)), 0) }}</div>
        </div>
        <div class="info-item">
          <div class="label">Aktive Worker</div>
          <div class="value">{{ workerStatus.length }}</div>
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
</div>
      <AdminDataTable
        :columns="workerColumns"
        :rows="workerStatus"
        :loading="workerLoading"
        :page-size="20"
        persist-key="admin-worker-status"
        empty-text="Keine aktiven Worker gefunden."
      >
        <template #cell-timestamp="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-last_job_time="{ value }">{{ formatDateTime(value) }}</template>
        <template #cell-jobs="{ row }">
          <ul v-if="row.jobs && row.jobs.length">
            <li v-for="(job, idx) in row.jobs" :key="idx">
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
.card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.75rem; background: #fff; display: flex; flex-direction: column; gap: 0.5rem; }
.card-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.5rem; }
.info-item { padding: 0.5rem; border: 1px solid #e5e7eb; border-radius: 6px; background: #f8fafc; }
.label { font-weight: 600; color: #475569; }
.value { font-weight: 700; color: #0f172a; word-break: break-word; }
.muted { color: #6b7280; font-size: 0.9rem; }
.latency-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.5rem; }
.latency-item { padding: 0.75rem; border: 1px solid #e5e7eb; border-radius: 8px; background: #f8fafc; }
.status-ok { border-color: #bbf7d0; background: #f0fdf4; }
.status-failed { border-color: #fecaca; background: #fef2f2; }
.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }
.table { width: 100%; border-collapse: collapse; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 0.5rem; text-align: left; }
.table-wrapper { overflow-x: auto; }
.pill { display: inline-flex; align-items: center; padding: 0.15rem 0.45rem; border-radius: 999px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.02em; }
.pill-ok { background: #ecfdf3; color: #15803d; border: 1px solid #bbf7d0; }
.pill-failed { background: #fef2f2; color: #b91c1c; border: 1px solid #fecdd3; }
.wrap { max-width: 320px; white-space: pre-wrap; word-break: break-word; }
.inline { display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 600; }
</style>
