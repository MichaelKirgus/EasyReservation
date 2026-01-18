<template>
  <div>
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
      <template #cell-active="{ row }">
        <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loading" />
      </template>
      <template #cell-actions="{ row }">
        <IconButton icon="play" label="Sofort ausführen" class="ghost" @click="runNow(row)" :disabled="loading || row.executed || !row.active" />
        <IconButton icon="edit" label="Bearbeiten" class="ghost" @click="editTask(row)" :disabled="loading" />
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
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import TaskDialog from './TaskDialog.vue' // Dialog für Create/Edit
import IconButton from './IconButton.vue'
import axios from 'axios'

function apiConfig() {
  const apiKey = localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || '';
  return { headers: { 'X-Api-Key': apiKey } };
}


const tasks = ref([])
const nextRunAt = ref(null)
const loading = ref(false)
const showDialog = ref(false)
const selectedTask = ref(null)

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'type', label: 'Typ' },
  { key: 'run_at', label: 'Ausführungszeit' },
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
