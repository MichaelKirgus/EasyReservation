<template>
  <div>
    <!-- Ausgelagerter Inhalt aus AdminScheduledTasks.vue fÃ¼r geplante Aufgaben -->
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>{{ tr('scheduled_tasks_title') }}</span>
      <IconButton icon="plus" :label="tr('scheduled_tasks_button_new_task')" class="primary" variant="success" @click="createTask" />
    </h2>
    <div v-if="nextRunAt" class="info-box" style="margin-bottom:0.5em;">
      <strong style="margin-right:0.5em;">{{ tr('scheduled_tasks_next_run_at') }}</strong>
      <span>{{ formatDateTime(nextRunAt) }}</span>
    </div>
    <AdminDataTable
      :columns="columns"
      :rows="tasks"
      :loading="loading"
    >
      <template #cell-executed="{ value }">
        <input type="checkbox" :checked="value" disabled />
      </template>
      <template #cell-executed_at="{ value }">
        <span>{{ value ? formatDateTime(value) : 'â€“' }}</span>
      </template>
      <template #cell-planned_run_at="{ value }">
        <span>{{ value ? formatDateTime(value) : 'â€“' }}</span>
      </template>
      <template #cell-next_run_at="{ value }">
        <span>{{ value ? formatDateTime(value) : 'â€“' }}</span>
      </template>
      <template #cell-last_run_at="{ value }">
        <span>{{ value ? formatDateTime(value) : 'â€“' }}</span>
      </template>
      <template #cell-active="{ row }">
        <input type="checkbox" :checked="row.active" @change="toggleActive(row)" :disabled="loading" />
      </template>
      <template #cell-run_once="{ row, value }">
        <input type="checkbox" :checked="value" @change="toggleRunOnce(row)" :disabled="loading" />
      </template>
      <template #cell-skip_if_overdue="{ row, value }">
        <input type="checkbox" :checked="value" @change="toggleSkipIfOverdue(row)" :disabled="loading" />
      </template>
      <template #row-actions="{ row }">
        <IconButton icon="play" :label="tr('scheduled_tasks_button_run_now')" class="ghost" @click.stop="runNow(row)" :disabled="loading" />
        <IconButton icon="pencil" :label="tr('scheduled_tasks_button_edit')" class="ghost" @click.stop="editTask(row)" :disabled="loading" />
        <IconButton icon="trash" :label="tr('scheduled_tasks_button_delete')" class="ghost" variant="danger" @click.stop="deleteTask(row)" :disabled="loading" />
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
import { ref, computed, onMounted } from 'vue'
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
  if (!val) return 'â€“';
  // If it's a unix timestamp (number), convert directly
  if (typeof val === 'number') {
    const d = new Date(val * 1000);
    if (isNaN(d)) return String(val);
    return d.toLocaleString(navigator.language, {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
  }
  // Interpret ISO strings without timezone suffix as UTC
  let iso = String(val).replace(' ', 'T');
  if (!/Z|[+-]\d{2}(:\d{2})?$/.test(iso)) iso += 'Z';
  const d = new Date(iso);
  if (isNaN(d)) return val;
  return d.toLocaleString(navigator.language, {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit'
  });
}

const tasks = ref([])
const nextRunAt = ref(null)
const loading = ref(false)
const showDialog = ref(false)
const selectedTask = ref(null)

// Columns - defined as computed to ensure translations are loaded
const columns = computed(() => [
  { key: 'id', label: tr('scheduled_tasks_column_id') },
  { key: 'type', label: tr('scheduled_tasks_column_type'),
    formatter: (type) => {
      switch (type) {
        case 'attendees_email_broadcast': return tr('scheduled_tasks_type_attendees_email_broadcast');
        case 'waitlist_email_broadcast': return tr('scheduled_tasks_type_waitlist_email_broadcast');
        case 'custom_email_broadcast': return tr('scheduled_tasks_type_custom_email_broadcast');
        case 'change_setting': return tr('scheduled_tasks_type_change_setting');
        case 'webhook': return tr('scheduled_tasks_type_webhook');
        default: return type;
      }
    }
  },
  { key: 'planned_run_at', label: tr('scheduled_tasks_column_planned_run_at') },
  { key: 'reference_type', label: tr('scheduled_tasks_column_reference_type'),
    formatter: (type) => {
      if (type === 'cron') return tr('scheduled_tasks_reference_type_cron');
      if (type === 'fixed') return tr('scheduled_tasks_reference_type_fixed');
      if (type === 'event') return tr('scheduled_tasks_reference_type_event');
      if (type === 'reservation') return tr('scheduled_tasks_reference_type_reservation');
      if (type === 'user') return tr('scheduled_tasks_reference_type_user');
      return type;
    }
  },
  { key: 'reference_id', label: tr('scheduled_tasks_column_reference_id') },
  { key: 'relative_to', label: tr('scheduled_tasks_column_relative_to') },
  { key: 'relative_offset_minutes', label: tr('scheduled_tasks_column_offset_minutes') },
  { key: 'executed', label: tr('scheduled_tasks_column_executed') },
  { key: 'executed_at', label: tr('scheduled_tasks_column_executed_at') },
  { key: 'active', label: tr('scheduled_tasks_column_active') },
  { key: 'run_once', label: tr('scheduled_tasks_column_run_once') },
  { key: 'skip_if_overdue', label: tr('scheduled_tasks_column_skip_if_overdue') }
])

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
  // Komplettes Task-Objekt Ã¼bergeben, nur active Ã¤ndern
  saveTask({ ...task, active: !task.active })
}

function toggleRunOnce(task) {
  saveTask({ ...task, run_once: !task.run_once })
}

function toggleSkipIfOverdue(task) {
  saveTask({ ...task, skip_if_overdue: !task.skip_if_overdue })
}

onMounted(fetchTasks)
</script>
