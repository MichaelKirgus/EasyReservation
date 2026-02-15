<template>
  <div>
    <!-- Ausgelagerter Inhalt aus AdminScheduledTasks.vue für geplante Aufgaben -->
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>Geplante Aufgaben</span>
      <IconButton icon="plus" label="Neue Aufgabe" class="primary" variant="success" @click="createTask" />
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
      <template #row-actions="{ row }">
        <IconButton icon="play" label="Sofort ausführen" class="ghost" @click.stop="runNow(row)" :disabled="loading" />
        <IconButton icon="pencil" label="Bearbeiten" class="ghost" @click="editTask(row)" :disabled="loading" />
        <IconButton icon="trash" label="Löschen" class="ghost" variant="danger" @click="deleteTask(row)" :disabled="loading" />
      </template>
    </AdminDataTable>
    <TaskDialog
      v-if="showDialog"
      :task="selectedTask"
      :key="selectedTask ? selectedTask.id || 'new' : 'new'"
      @close="closeDialog"
      @save="saveTask"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import TaskDialog from './TaskDialog.vue'
import IconButton from './IconButton.vue'
import axios from 'axios'
import { buildAdminHeaders } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

function apiConfig() {
  return { headers: buildAdminHeaders() };
}

function formatDateTime(val) {
  if (!val) return '–';
  // Falls kein Z oder Zeitzonen-Offset, als UTC interpretieren
  let iso = val.replace(' ', 'T');
  if (!/Z|[+-]\d{2}:\d{2}$/.test(iso)) iso += 'Z';
  const d = new Date(iso);
  if (isNaN(d)) return val;
  return d.toLocaleString();
}

const tasks = ref([])
const nextRunAt = ref(null)
const loading = ref(false)
const showDialog = ref(false)
const selectedTask = ref(null)

const columns = [
  { key: 'id', label: 'ID' },
  { key: 'type', label: 'Typ',
    formatter: (type) => {
      switch (type) {
        case 'attendees_email_broadcast': return 'E-Mail an Teilnehmer';
        case 'waitlist_email_broadcast': return 'E-Mail an Warteliste';
        case 'custom_email_broadcast': return 'E-Mail an benutzerdefinierte Adressen';
        case 'change_setting': return 'Einstellung ändern';
        case 'webhook': return 'Webhook';
        default: return type;
      }
    }
  },
  { key: 'run_at', label: 'Ausführungszeit' },
  { key: 'planned_run_at', label: 'Geplantes Ausführungsdatum' },
  { key: 'reference_type', label: 'Referenztyp',
    formatter: (type) => {
      if (type === 'cron') return 'Cron';
      if (type === 'event') return 'Event';
      if (type === 'reservation') return 'Reservierung';
      if (type === 'user') return 'Benutzer';
      return type;
    }
  },
  { key: 'reference_id', label: 'Referenz-ID' },
  { key: 'relative_to', label: 'Relativ zu' },
  { key: 'relative_offset_minutes', label: 'Offset (Minuten)' },
  { key: 'executed', label: 'Ausgeführt' },
  { key: 'executed_at', label: 'Ausgeführt am' },
  { key: 'active', label: 'Aktiv' }
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
  // Erstelle eine tiefe Kopie, um Proxy-Probleme zu vermeiden
  selectedTask.value = JSON.parse(JSON.stringify(task));
  showDialog.value = true;
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
  if (!confirm(tr('really_delete_scheduled_task'))) {
    return
  }
  loading.value = true
  axios.delete(`/api/admin/scheduled-tasks/${task.id}`, apiConfig())
    .then(fetchTasks)
    .finally(() => { loading.value = false })
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
  selectedTask.value = null;
  showDialog.value = false;
}

function toggleActive(task) {
  // Komplettes Task-Objekt übergeben, nur active ändern
  saveTask({ ...task, active: !task.active })
}

onMounted(fetchTasks)
</script>
