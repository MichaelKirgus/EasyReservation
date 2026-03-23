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
    </div>

    <!-- Charts -->
    <div class="charts-container">
      <!-- Pie Chart: Reservation vs Waitlist -->
      <div class="chart-section">
        <h3>{{ tr('dashboard_chart_reservation_vs_waitlist') }}</h3>
        <canvas id="reservation-waitlist-pie" style="width:100%;height:auto;"></canvas>
      </div>

      <!-- Line/Bar Chart: Daily Trends -->
      <div class="chart-section">
        <h3>{{ tr('dashboard_chart_daily_trends') }}</h3>
        <canvas id="daily-trends-chart" style="width:100%;height:auto;"></canvas>
      </div>
    </div>

    <!-- Action List Selector -->
    <div class="action-list-selector">
      <h3>{{ tr('dashboard_action_list_select') }}</h3>
      <select v-model="selectedActionListId" :disabled="loading || !actionLists.length">
        <option value="" disabled>{{ tr('dashboard_action_list_select_placeholder') }}</option>
        <option v-for="list in actionLists" :key="list.id" :value="list.id">
          {{ list.name }}
        </option>
      </select>
      <button 
        @click="executeSelected" 
        :disabled="!selectedActionListId || loading"
        class="primary"
      >
        {{ tr('dashboard_action_list_execute') }}
      </button>
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
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'
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

let pieChart = null
let barChart = null

// Load statistics and action lists on mount
onMounted(() => {
  loadStats()
  loadActionLists()
})

async function loadPublicUrl() {
  try {
    const res = await adminFetch('moderation-dashboard/public-url')
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
    const res = await adminFetch('moderation-dashboard/stats')
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
    const res = await adminFetch('action-lists')
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

  // Create Pie Chart: Reservation vs Waitlist
  const pieCtx = document.getElementById('reservation-waitlist-pie')?.getContext('2d')
  if (pieCtx && stats.value.reservation_count !== undefined && stats.value.waitlist_count !== undefined) {
    pieChart = new Chart(pieCtx, {
      type: 'pie',
      data: {
        labels: [tr('dashboard_chart_reservation'), tr('dashboard_chart_waitlist')],
        datasets: [{
          data: [stats.value.reservation_count, stats.value.waitlist_count],
          backgroundColor: [successBg, errorBg],
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 1.5,
        animation: {
          duration: 0
        },
        plugins: {
          legend: {
            position: 'bottom',
            color: getComputedStyle(document.documentElement).getPropertyValue('--text').trim()
          }
        }
      }
    })
  }

  // Create Bar Chart: Daily Trends
  const barCtx = document.getElementById('daily-trends-chart')?.getContext('2d')
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
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2,
        animation: {
          duration: 0
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { stepSize: 1 }
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
    const res = await adminFetch(`moderation-dashboard/action-list/${selectedActionListId.value}/execute`, { method: 'POST' })
    
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

.charts-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
  gap: 2rem;
  margin-bottom: 2rem;
}

.chart-section {
  background: var(--surface);
  border-radius: 8px;
  padding: 1.5rem;
  box-shadow: 0 1px 3px var(--shadow);
  width: 100%;
  max-width: 450px;
}

.chart-section h3 {
  margin-top: 0;
  margin-bottom: 1rem;
  color: var(--text);
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
  margin-bottom: 1rem;
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
</style>
