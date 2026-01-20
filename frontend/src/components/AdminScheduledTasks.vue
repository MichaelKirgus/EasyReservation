<template>
  <div>
    <div class="tabs">
      <button :class="['tab', { active: activeTab === 'tasks' }]" @click="activeTab = 'tasks'">Geplante Aufgaben</button>
      <button :class="['tab', { active: activeTab === 'triggers' }]" @click="activeTab = 'triggers'">Ereignis-Trigger</button>
    </div>
    <div v-if="activeTab==='tasks'">
      <h2 style="display:flex;align-items:center;justify-content:space-between;">
        <span>Geplante Aufgaben</span>
        <IconButton icon="plus" label="Neue Aufgabe" class="primary" @click="createTask" />
      </h2>
      <div v-if="nextRunAt" class="info-box" style="margin-bottom:0.5em;">
        <strong>Nächste geplante Ausführung:</strong>
        <span>{{ formatDateTime(nextRunAt) }}</span>
      </div>
      <AdminDataTable
        :columns="columns"
        :rows="tasks"
        :loading="loading"
      >
        <template #cell-executed="{ value }">
          <span v-if="value">✅</span>
          <span v-else>❌</span>
        </template>
        <template #cell-executed_at="{ value }">
          <span>{{ value ? formatDateTime(value) : '–' }}</span>
        </template>
        <template #cell-planned_run_at="{ value }">
          <span>{{ value ? formatDateTime(value) : '–' }}</span>
        </template>
        <template #cell-active="{ row }">
          <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loading" />
        </template>
        <template #cell-actions="{ row }">
          <IconButton icon="play" label="Sofort ausführen" class="ghost" @click="runNow(row)" :disabled="loading || row.executed || !row.active" />
          <IconButton icon="pencil" label="Bearbeiten" class="ghost" @click="editTask(row)" :disabled="loading" />
          <IconButton icon="trash" label="Löschen" class="ghost" @click="deleteTask(row)" :disabled="loading" />
        </template>
      </AdminDataTable>
      <TaskDialog
        v-if="showDialog"
        :task="selectedTask"
        @close="closeDialog"
        @save="saveTask"
      />
    </div>
    <div v-else>
      <AdminEventTriggers />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import TaskDialog from './TaskDialog.vue' // Dialog für Create/Edit
import IconButton from './IconButton.vue'
import AdminEventTriggers from './AdminEventTriggers.vue'
import axios from 'axios'

function apiConfig() {
  const apiKey = localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || '';
  return { headers: { 'X-Api-Key': apiKey } };
}

function formatDateTime(val) {
  if (!val) return '–';
  // Versuche ISO-Format zu erzwingen (UTC), falls nötig
  let iso = val.replace(' ', 'T');
  if (!iso.endsWith('Z')) iso += 'Z';
  const d = new Date(iso);
  if (isNaN(d)) return val;
  return d.toLocaleString();
}

const activeTab = ref('tasks')

const tasks = ref([])
const nextRunAt = ref(null)
const loading = ref(false)
const showDialog = ref(false)
const selectedTask = ref(null)

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'type', label: 'Typ' },
  { key: 'run_at', label: 'Ausführungszeit' },
  { key: 'planned_run_at', label: 'Geplantes Ausführungsdatum' }, // NEU
  { key: 'reference_type', label: 'Referenztyp' },
  { key: 'reference_id', label: 'Referenz-ID' },
  { key: 'relative_to', label: 'Relativ zu' },
  { key: 'relative_offset_minutes', label: 'Offset (Minuten)' },
  { key: 'executed', label: 'Ausgeführt' },
  { key: 'executed_at', label: 'Ausgeführt am' },
  { key: 'active', label: 'Aktiv' },
  { key: 'actions', label: 'Aktionen' }
]


function fetchTasks() {
  loading.value = true
  axios.get('/api/admin/scheduled-tasks', apiConfig())
    .then(res => {
      if (res.data && Array.isArray(res.data.tasks)) {
        tasks.value = res.data.tasks
        nextRunAt.value = res.data.next_run_at
      } else if (Array.isArray(res.data)) {
        tasks.value = res.data
        nextRunAt.value = null
      }
    })
    .finally(() => {
      loading.value = false
    })
}

function editTask(task) {
  selectedTask.value = { ...task }
  showDialog.value = true
}

function createTask() {
  selectedTask.value = null
  showDialog.value = true
}

function runNow(task) {
  loading.value = true
  axios.post(`/api/admin/scheduled-tasks/${task.id}/run-now`, {}, apiConfig())
    .then(fetchTasks)
    .finally(() => { loading.value = false })
}


function deleteTask(task) {
  if (confirm('Wirklich löschen?')) {
    loading.value = true
    axios.delete(`/api/admin/scheduled-tasks/${task.id}`, apiConfig())
      .then(fetchTasks)
      .finally(() => { loading.value = false })
  }
}


function saveTask(task) {
  loading.value = true
  const req = task.id
    ? axios.put(`/api/admin/scheduled-tasks/${task.id}`, task, apiConfig())
    : axios.post('/api/admin/scheduled-tasks', task, apiConfig())
  req.then(fetchTasks)
     .finally(() => { loading.value = false; showDialog.value = false })
}

function closeDialog() {
  showDialog.value = false
}

onMounted(fetchTasks)
</script>

<style scoped>
button.success, .icon-btn.success {
  background: #22c55e;
  color: #fff;
  border: 1px solid #16a34a;
  border-radius: 6px;
  padding: 0.5em 1.1em;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
button.success:hover, .icon-btn.success:hover {
  background: #16a34a;
}

    .tabs {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .tab {
        padding: 0.5rem 0.75rem;
        border: 1px solid #d1d5db;
        background: #f8fafc;
        border-radius: 8px;
        cursor: pointer;
        color: #0f172a;
        font-weight: 600;
    }

        .tab.active {
            background: #2563eb;
            color: #fff;
            border-color: #1d4ed8;
        }
</style>
