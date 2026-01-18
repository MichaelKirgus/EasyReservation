<template>
  <div>
    <h2 style="display:flex;align-items:center;justify-content:space-between;">
      <span>Geplante Aufgaben</span>
      <IconButton icon="plus" label="Neue Aufgabe" class="primary" @click="createTask" />
    </h2>
    <AdminDataTable
      :columns="columns"
      :rows="tasks"
      :loading="loading"
      @edit="editTask"
      @delete="deleteTask"
      :action-buttons="actionButtons"
    />
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

const tasks = ref([])
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
  { key: 'actions', label: 'Aktionen' }
]


function fetchTasks() {
  loading.value = true
  axios.get('/api/admin/scheduled-tasks')
    .then(res => {
      tasks.value = res.data
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
    axios.delete(`/api/admin/scheduled-tasks/${task.id}`)
      .then(fetchTasks)
      .finally(() => { loading.value = false })
  }
}


function saveTask(task) {
  loading.value = true
  const req = task.id
    ? axios.put(`/api/admin/scheduled-tasks/${task.id}`, task)
    : axios.post('/api/admin/scheduled-tasks', task)
  req.then(fetchTasks)
     .finally(() => { loading.value = false; showDialog.value = false })
}

function closeDialog() {
  showDialog.value = false
}

onMounted(fetchTasks)
</script>
