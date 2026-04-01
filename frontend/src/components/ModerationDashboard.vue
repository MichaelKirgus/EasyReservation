<template>
  <div class="moderation-dashboard">
    <!-- Public URL Info Box -->
    <div v-if="publicUrl" class="public-url-info-box">
      <h3>{{ tr('dashboard_public_url_title') }}</h3>
      <p class="public-url-text">{{ publicUrl }}</p>
      <button
        @click="copyPublicUrl"
        :disabled="loading"
        class="primary copy-btn"
      >
        {{ tr('dashboard_public_url_copy') }}
      </button>
      <span v-if="copySuccess" class="copy-success">{{ tr('dashboard_public_url_copied') }}</span>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-cards">
      <div class="stat-card reservation-count">
        <div class="stat-card-label">{{ tr('dashboard_stat_reservation_count') }}</div>
        <div class="stat-card-value">{{ stats?.reservation_count || 0 }}</div>
      </div>
      <div class="stat-card waitlist-count">
        <div class="stat-card-label">{{ tr('dashboard_stat_waitlist_count') }}</div>
        <div class="stat-card-value">{{ stats?.waitlist_count || 0 }}</div>
      </div>
      <div class="stat-card mail-validation-pending">
        <div class="stat-card-label">{{ tr('dashboard_stat_mail_validation_pending') }}</div>
        <div class="stat-card-value">{{ stats?.mail_validation_pending || 0 }}</div>
      </div>
      <div class="stat-card rate-limit-entries">
        <div class="stat-card-label">{{ tr('dashboard_stat_rate_limit_entries') }}</div>
        <div class="stat-card-value">{{ stats?.rate_limit_entries || 0 }}</div>
      </div>
      <div class="stat-card conversion-rate">
        <div class="stat-card-label">{{ tr('dashboard_stat_waitlist_conversion_rate') }}</div>
        <div class="stat-card-value">{{ waitlistConversionRate.toFixed(1) }}%</div>
        <div class="stat-card-subtext">{{ waitlistConversionPromoted }} / {{ waitlistConversionTotal }}</div>
      </div>
    </div>

    <div class="moderator-insights-grid">
      <section class="insight-card">
        <h3>{{ tr('dashboard_pending_validation_age_title') }}</h3>
        <div class="bucket-grid">
          <div class="bucket-item">
            <span class="bucket-label">{{ tr('dashboard_pending_validation_under_1h') }}</span>
            <strong class="bucket-value">{{ pendingValidationBuckets.under_1h }}</strong>
          </div>
          <div class="bucket-item">
            <span class="bucket-label">{{ tr('dashboard_pending_validation_1h_24h') }}</span>
            <strong class="bucket-value">{{ pendingValidationBuckets.between_1h_24h }}</strong>
          </div>
          <div class="bucket-item stale">
            <span class="bucket-label">{{ tr('dashboard_pending_validation_over_24h') }}</span>
            <strong class="bucket-value">{{ pendingValidationBuckets.over_24h }}</strong>
          </div>
        </div>
      </section>

      <section class="insight-card">
        <h3>{{ tr('dashboard_reservation_funnel_title') }}</h3>
        <div class="funnel-list">
          <div v-for="step in reservationFunnelSteps" :key="step.key" class="funnel-row">
            <span class="funnel-label">{{ step.label }}</span>
            <div class="funnel-track">
              <div class="funnel-fill" :style="{ width: funnelBarWidth(step.value) }"></div>
            </div>
            <strong class="funnel-value">{{ step.value }}</strong>
          </div>
        </div>
      </section>
    </div>

    <div class="moderator-insights-grid">
      <section class="insight-card">
        <h3>{{ tr('dashboard_upcoming_scheduled_tasks_title') }}</h3>
        <div v-if="upcomingScheduledTasks.length" class="scheduled-task-list">
          <div v-for="task in upcomingScheduledTasks" :key="`upcoming-${task.id}`" class="scheduled-task-row">
            <div class="scheduled-task-main">
              <strong class="scheduled-task-name">{{ scheduledTaskName(task) }}</strong>
              <span class="scheduled-task-meta">{{ tr('dashboard_scheduled_task_planned_for') }}: {{ formatDateTime(task.planned_run_at) }}</span>
            </div>
            <span class="scheduled-task-chip">#{{ task.id }}</span>
          </div>
        </div>
        <p v-else class="empty-state-text">{{ tr('dashboard_scheduled_tasks_empty_upcoming') }}</p>
      </section>

      <section class="insight-card">
        <h3>{{ tr('dashboard_last_executed_tasks_title') }}</h3>
        <div v-if="lastExecutedScheduledTasks.length" class="scheduled-task-list">
          <div v-for="execution in lastExecutedScheduledTasks" :key="`executed-${execution.id}`" class="scheduled-task-row">
            <div class="scheduled-task-main">
              <strong class="scheduled-task-name">{{ scheduledTaskName(execution) }}</strong>
              <span class="scheduled-task-meta">{{ tr('dashboard_scheduled_task_executed_at') }}: {{ formatDateTime(execution.finished_at) }}</span>
            </div>
            <span class="scheduled-task-chip" :class="execution.status === 'failed' ? 'error' : 'success'">
              {{ execution.status === 'failed' ? tr('dashboard_scheduled_task_status_failed') : tr('dashboard_scheduled_task_status_success') }}
            </span>
          </div>
        </div>
        <p v-else class="empty-state-text">{{ tr('dashboard_scheduled_tasks_empty_executed') }}</p>
      </section>
    </div>

    <!-- Charts -->
    <div class="charts-container">
      <!-- Pie Chart: Reservation vs Waitlist -->
      <div class="chart-section">
        <h3>{{ tr('dashboard_chart_reservation_vs_waitlist') }}</h3>
        <div class="chart-canvas-wrap pie-wrap">
          <canvas ref="reservationWaitlistCanvas"></canvas>
        </div>
      </div>

      <!-- Line/Bar Chart: Daily Trends -->
      <div class="chart-section">
        <h3>{{ tr('dashboard_chart_daily_trends') }}</h3>
        <div class="chart-canvas-wrap bar-wrap">
          <canvas ref="dailyTrendsCanvas"></canvas>
        </div>
      </div>
    </div>

    <!-- Action List Selector -->
    <div class="action-list-selector">
      <h3>{{ tr('dashboard_action_list_select') }}</h3>
      <div class="action-list-controls">
        <select v-model="selectedActionListId" :disabled="loading || !actionLists.length">
          <option value="" disabled>{{ tr('dashboard_action_list_select_placeholder') }}</option>
          <option v-for="list in actionLists" :key="list.id" :value="list.id">
            {{ list.name }}
          </option>
        </select>
        <IconButton
          icon="play"
          size="lg"
          variant="success"
          :label="tr('dashboard_action_list_execute')"
          :title="tr('dashboard_action_list_execute')"
          :disabled="!selectedActionListId || loading"
          @click="executeSelected"
        />
      </div>
    </div>

    <!-- Loading and Error States -->
    <div v-if="loading" class="loading-overlay">
      <div class="spinner"></div>
      <span>{{ tr('loading') }}</span>
    </div>
    <div v-if="error" class="error-message">{{ error }}</div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'
import IconButton from './IconButton.vue'
import { Chart, PieController, BarController, CategoryScale, LinearScale, Tooltip, Legend, ArcElement, BarElement, LineElement, PointElement } from 'chart.js'

// Register Chart.js components
Chart.register(PieController, BarController, CategoryScale, LinearScale, Tooltip, Legend, ArcElement, BarElement, LineElement, PointElement)

const { tr } = useTranslation()

const stats = ref(null)
const actionLists = ref([])
const selectedActionListId = ref('')
const loading = ref(false)
const error = ref('')
const publicUrl = ref(null)
const copySuccess = ref(false)
const reservationWaitlistCanvas = ref(null)
const dailyTrendsCanvas = ref(null)
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

const waitlistConversionRate = computed(() => Number(stats.value?.waitlist_conversion?.rate || 0))
const waitlistConversionPromoted = computed(() => Number(stats.value?.waitlist_conversion?.promoted || 0))
const waitlistConversionTotal = computed(() => Number(stats.value?.waitlist_conversion?.total || 0))

const pendingValidationBuckets = computed(() => ({
  under_1h: Number(stats.value?.pending_validation_ages?.under_1h || 0),
  between_1h_24h: Number(stats.value?.pending_validation_ages?.between_1h_24h || 0),
  over_24h: Number(stats.value?.pending_validation_ages?.over_24h || 0),
}))

const upcomingScheduledTasks = computed(() => stats.value?.upcoming_scheduled_tasks || [])
const lastExecutedScheduledTasks = computed(() => stats.value?.last_executed_scheduled_tasks || [])

const reservationFunnelSteps = computed(() => {
  const funnel = stats.value?.reservation_funnel || {}
  return [
    { key: 'attempts', label: tr('dashboard_funnel_attempts'), value: Number(funnel.attempts || 0) },
    { key: 'pending_verification', label: tr('dashboard_funnel_pending_verification'), value: Number(funnel.pending_verification || 0) },
    { key: 'completed', label: tr('dashboard_funnel_completed'), value: Number(funnel.completed || 0) },
    { key: 'moved_to_waitlist', label: tr('dashboard_funnel_waitlist'), value: Number(funnel.moved_to_waitlist || 0) },
  ]
})

const reservationFunnelMax = computed(() => {
  const values = reservationFunnelSteps.value.map(step => step.value)
  const max = Math.max(...values, 0)
  return max > 0 ? max : 1
})

function funnelBarWidth(value) {
  return `${Math.max(8, Math.round((Number(value || 0) / reservationFunnelMax.value) * 100))}%`
}

function scheduledTaskName(item) {
  return item?.action_list_name || `${tr('scheduled_tasks_title')} #${item?.scheduled_task_id || item?.id || ''}`.trim()
}

function formatDateTime(value) {
  if (!value) return ''
  const date = new Date(value)
  if (isNaN(date.getTime())) return value
  return date.toLocaleString()
}

let pieChart = null
let barChart = null

// Load statistics and action lists on mount
onMounted(() => {
  loadStats()
  loadActionLists()
})

async function loadPublicUrl() {
  try {
    const res = await adminFetch('moderation-dashboard/public-url', {}, { routePrefixRef: routePrefix })
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    publicUrl.value = data.public_url
  } catch (e) {
    console.error('Failed to load public URL:', e)
  }
}

onUnmounted(() => {
  if (pieChart) pieChart.destroy()
  if (barChart) barChart.destroy()
})

async function loadStats() {
  loading.value = true
  error.value = ''
  
  // Destroy existing charts before loading new stats
  if (pieChart) pieChart.destroy()
  if (barChart) barChart.destroy()
  
  try {
    const res = await adminFetch('moderation-dashboard/stats', {}, { routePrefixRef: routePrefix })
    if (!res.ok) throw new Error(await res.text())
    stats.value = await res.json()
    publicUrl.value = stats.value.public_url
    
    // Update charts after stats are loaded (after loading is set to false)
    loading.value = false
    updateCharts()
  } catch (e) {
    error.value = tr('error_loading') + ': ' + e.message || String(e)
  } finally {
    loading.value = false
  }
}

async function loadActionLists() {
  try {
    const res = await adminFetch('action-lists', {}, { routePrefixRef: routePrefix })
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    
    // Filter only action lists with moderation_selectable = true
    actionLists.value = data.action_lists.filter(list => list.moderation_selectable)
  } catch (e) {
    console.error('Failed to load action lists:', e)
  }
}

function getCssVariable(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

async function copyPublicUrl() {
  if (!publicUrl.value) return
  
  try {
    await navigator.clipboard.writeText(publicUrl.value)
    copySuccess.value = true
    setTimeout(() => copySuccess.value = false, 2000)
  } catch (e) {
    console.error('Failed to copy URL:', e)
  }
}

function updateCharts() {
  if (!stats.value || loading.value) return

  // Destroy existing charts
  if (pieChart) pieChart.destroy()
  if (barChart) barChart.destroy()

  // Get theme colors from CSS variables
  const successBg = getCssVariable('--success-bg')
  const errorBg = getCssVariable('--error-bg')
  const successBorder = getCssVariable('--success-border')
  const textColor = getCssVariable('--text')
  const mutedTextColor = getCssVariable('--text-muted')
  const borderColor = getCssVariable('--border')

  // Create Pie Chart: Reservation vs Waitlist
  const pieCtx = reservationWaitlistCanvas.value?.getContext('2d')
  if (pieCtx && stats.value.reservation_count !== undefined && stats.value.waitlist_count !== undefined) {
    pieChart = new Chart(pieCtx, {
      type: 'pie',
      data: {
        labels: [tr('dashboard_chart_reservation'), tr('dashboard_chart_waitlist')],
        datasets: [{
          data: [stats.value.reservation_count, stats.value.waitlist_count],
          backgroundColor: [successBg, errorBg],
          borderWidth: 2,
          borderColor: borderColor,
          hoverOffset: 8,
          radius: '96%'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
          padding: 4
        },
        animation: {
          duration: 350
        },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: textColor,
              usePointStyle: true,
              pointStyle: 'circle',
              padding: 16,
              boxWidth: 8
            }
          }
        }
      }
    })
  }

  // Create Bar Chart: Daily Trends
  const barCtx = dailyTrendsCanvas.value?.getContext('2d')
  if (barCtx && stats.value.daily_trends && stats.value.daily_trends.length > 0) {
    barChart = new Chart(barCtx, {
      type: 'bar',
      data: {
        labels: stats.value.daily_trends.map(t => t.date),
        datasets: [{
          label: tr('dashboard_chart_daily_reservations'),
          data: stats.value.daily_trends.map(t => t.count),
          backgroundColor: successBg,
          borderColor: successBorder,
          borderWidth: 1,
          borderRadius: 8,
          maxBarThickness: 44,
          categoryPercentage: 0.8,
          barPercentage: 0.9
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
          padding: {
            top: 8,
            right: 8,
            bottom: 0,
            left: 0
          }
        },
        animation: {
          duration: 350
        },
        scales: {
          x: {
            ticks: {
              color: mutedTextColor,
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 7
            },
            grid: {
              display: false
            }
          },
          y: {
            beginAtZero: true,
            ticks: {
              color: mutedTextColor,
              stepSize: 1,
              precision: 0
            },
            grid: {
              color: borderColor
            }
          }
        },
        plugins: {
          legend: { display: false }
        }
      }
    })
  }
}

async function executeSelected() {
  if (!selectedActionListId.value) return

  loading.value = true
  error.value = ''
  
  try {
    const res = await adminFetch(
      `moderation-dashboard/action-list/${selectedActionListId.value}/execute`,
      { method: 'POST' },
      { routePrefixRef: routePrefix }
    )
    
    if (!res.ok) {
      const text = await res.text()
      throw new Error(text)
    }
    
    const data = await res.json()
    
    if (data.success) {
      error.value = ''
      // Refresh stats after execution
      setTimeout(() => loadStats(), 1000)
    } else {
      error.value = data.error || tr('action_list_execution_failed')
    }
  } catch (e) {
    error.value = e.message || String(e)
  } finally {
    loading.value = false
  }
}

// Watch for stats changes to update charts (only when not loading)
watch(stats, () => {
  if (!loading.value && stats.value) updateCharts()
}, { deep: true })
</script>

<style scoped>
.moderation-dashboard {
  padding: 1rem;
}

.stats-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 2rem;
}

.stat-card {
  background: var(--surface-muted);
  border-radius: 8px;
  padding: 1.5rem;
  text-align: center;
}

.stat-card-label {
  font-size: 0.875rem;
  color: var(--text-muted);
  margin-bottom: 0.5rem;
}

.stat-card-value {
  font-size: 1.5rem;
  font-weight: bold;
  color: var(--text);
}

.reservation-count .stat-card-value { color: var(--success-text); }
.waitlist-count .stat-card-value { color: var(--error-text); }
.mail-validation-pending .stat-card-value { color: var(--primary); }
.rate-limit-entries .stat-card-value { color: var(--focus); }
.conversion-rate .stat-card-value { color: var(--success-text); }

.stat-card-subtext {
  margin-top: 0.35rem;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.moderator-insights-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.insight-card {
  background: var(--surface);
  border-radius: 8px;
  padding: 1rem;
  box-shadow: 0 1px 3px var(--shadow);
}

.insight-card h3 {
  margin: 0 0 0.85rem;
  color: var(--text);
}

.bucket-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
}

.bucket-item {
  background: var(--surface-muted);
  border-radius: 8px;
  padding: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.bucket-item.stale {
  border: 1px solid var(--error-border);
}

.bucket-label {
  font-size: 0.75rem;
  color: var(--text-muted);
}

.bucket-value {
  font-size: 1.2rem;
  color: var(--text);
}

.funnel-list {
  display: grid;
  gap: 0.6rem;
}

.funnel-row {
  display: grid;
  grid-template-columns: minmax(130px, 1fr) 2fr auto;
  align-items: center;
  gap: 0.6rem;
}

.funnel-label {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.funnel-track {
  background: var(--surface-muted);
  border-radius: 999px;
  height: 11px;
  overflow: hidden;
}

.funnel-fill {
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, var(--success-bg), var(--success-border));
}

.funnel-value {
  min-width: 2ch;
  color: var(--text);
  font-size: 0.85rem;
  text-align: right;
}

.scheduled-task-list {
  display: grid;
  gap: 0.75rem;
}

.scheduled-task-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.8rem 0.9rem;
  border-radius: 8px;
  background: var(--surface-muted);
}

.scheduled-task-main {
  min-width: 0;
  display: grid;
  gap: 0.2rem;
}

.scheduled-task-name {
  color: var(--text);
}

.scheduled-task-meta {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.scheduled-task-chip {
  flex-shrink: 0;
  padding: 0.3rem 0.55rem;
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-muted);
  font-size: 0.75rem;
  border: 1px solid var(--border);
}

.scheduled-task-chip.success {
  color: var(--success-text);
  border-color: var(--success-border);
}

.scheduled-task-chip.error {
  color: var(--error-text);
  border-color: var(--error-border);
}

.empty-state-text {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.charts-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 1rem;
  margin-bottom: 2rem;
}

.chart-section {
  background: var(--surface);
  border-radius: 8px;
  padding: 1rem;
  box-shadow: 0 1px 3px var(--shadow);
  width: 100%;
  min-height: 320px;
  display: flex;
  flex-direction: column;
}

.chart-section h3 {
  margin-top: 0;
  margin-bottom: 0.75rem;
  color: var(--text);
}

.chart-canvas-wrap {
  position: relative;
  width: 100%;
  flex: 1;
  min-height: 0;
}

.pie-wrap {
  height: clamp(220px, 34vh, 360px);
}

.bar-wrap {
  height: clamp(240px, 36vh, 390px);
}

.action-list-selector {
  background: var(--surface);
  border-radius: 8px;
  padding: 1.5rem;
  box-shadow: 0 1px 3px var(--shadow);
}

.action-list-selector h3 {
  margin-top: 0;
  margin-bottom: 1rem;
  color: var(--text);
}

select {
  width: 100%;
  padding: 0.5rem;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: var(--surface);
  margin-bottom: 0;
}

.action-list-controls {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.75rem;
  align-items: center;
}

button {
  padding: 0.75rem 1.5rem;
  border-radius: 4px;
  font-weight: bold;
  cursor: pointer;
  transition: all 0.2s;
}

button.primary {
  background: var(--success-bg);
  color: var(--success-text);
  border: none;
}

button.primary:hover:not(:disabled) {
  background: var(--success-border);
}

button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid var(--primary-contrast);
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.error-message {
  background: var(--error-bg);
  color: var(--error-text);
  padding: 1rem;
  border-radius: 8px;
  margin-bottom: 1rem;
}

.public-url-info-box {
  background: var(--surface-muted);
  color: var(--text);
  border-radius: 8px;
  padding: 1.5rem;
  box-shadow: 0 1px 3px var(--shadow);
  margin-bottom: 2rem;
  text-align: center;
}

.public-url-info-box h3 {
  margin-top: 0;
  margin-bottom: 1rem;
  color: var(--text);
}

.public-url-text {
  font-family: monospace;
  font-size: 1.1rem;
  word-break: break-all;
  background: var(--surface);
  padding: 0.75rem;
  border-radius: 4px;
  margin-bottom: 1rem;
  display: block;
}

.copy-btn {
  margin-right: 0.5rem;
}

.copy-success {
  color: var(--success-text);
  font-size: 0.875rem;
}

@media (max-width: 768px) {
  .charts-container {
    grid-template-columns: 1fr;
  }

  .chart-section {
    min-height: 280px;
  }

  .action-list-controls {
    grid-template-columns: 1fr;
    justify-items: start;
  }

  .bucket-grid {
    grid-template-columns: 1fr;
  }

  .funnel-row {
    grid-template-columns: 1fr;
    gap: 0.35rem;
  }

  .funnel-value {
    text-align: left;
  }

  .action-list-controls :deep(.icon-btn) {
    width: 44px;
    height: 44px;
  }
}
</style>
