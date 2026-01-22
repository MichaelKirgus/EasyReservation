
<template>
  <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
    <IconButton icon="plus" label="Platzhalter hinzufügen" @click="openAddDialog" />
  </div>
  <AdminDataTable
    :columns="columns"
    :rows="rows"
    :loading="loading"
    :editable="true"
    :deletable="true"
    :creatable="false"
    @update="openEditDialog"
    @delete="onDelete"
    title="Benutzerdefinierte Platzhalter"
  >
    <template #row-actions="{ row }">
      <IconButton icon="pencil" label="Bearbeiten" size="sm" variant="ghost" @click="openEditDialog(row)" />
      <IconButton icon="trash" label="Löschen" size="sm" variant="ghost" @click="onDelete(row)" />
    </template>
  </AdminDataTable>

  <div v-if="showDialog" class="modal-backdrop" @click.self="closeDialog">
    <div class="modal">
      <h3>{{ dialogMode === 'add' ? 'Platzhalter hinzufügen' : 'Platzhalter bearbeiten' }}</h3>
      <label class="form-field">Platzhalter-Key
        <input v-model="dialogData.key" :disabled="dialogMode === 'edit'" required />
      </label>
      <label class="form-field">Wert
        <textarea v-model="dialogData.value" rows="6" style="resize:vertical;width:100%" required></textarea>
      </label>
      <label class="form-field">Beschreibung
        <input v-model="dialogData.description" />
      </label>
      <div class="modal-actions" style="display: flex; gap: 0.5rem; justify-content: flex-end;">
        <IconButton icon="check" label="Speichern" @click="saveDialog" :disabled="!dialogData.key || !dialogData.value" />
        <IconButton icon="x" label="Abbrechen" variant="ghost" @click="closeDialog" />
      </div>
    </div>
  </div>
</template>


<script setup>
import { ref, onMounted } from 'vue';
import AdminDataTable from './AdminDataTable.vue';
import IconButton from './IconButton.vue';
import axios from 'axios';

const columns = [
  { key: 'key', label: 'Platzhalter', required: true },
  { key: 'value', label: 'Wert', required: true },
  { key: 'description', label: 'Beschreibung' },
];

const rows = ref([]);
const loading = ref(false);

function apiKeyHeader() {
  return {
    headers: {
      'X-Api-Key': localStorage.getItem('admin_api_key') || sessionStorage.getItem('admin_api_key') || ''
    }
  };
}

async function fetchRows() {
  loading.value = true;
  const { data } = await axios.get('/api/admin/custom-placeholders', apiKeyHeader());
  rows.value = data;
  loading.value = false;
}


const showDialog = ref(false);
const dialogMode = ref('add'); // 'add' | 'edit'
const dialogData = ref({ key: '', value: '', description: '' });
let editId = null;

function openAddDialog() {
  dialogMode.value = 'add';
  dialogData.value = { key: '', value: '', description: '' };
  editId = null;
  showDialog.value = true;
}

function openEditDialog(row) {
  dialogMode.value = 'edit';
  dialogData.value = { key: row.key, value: row.value, description: row.description };
  editId = row.id;
  showDialog.value = true;
}

function closeDialog() {
  showDialog.value = false;
}

function formatKey(key) {
  const trimmed = key.trim();
  if (/^\{\{.*\}\}$/.test(trimmed)) return trimmed;
  return `{{${trimmed.replace(/^\{+|\}+$/g, '')}}}`;
}

async function saveDialog() {
  const payload = {
    ...dialogData.value,
    key: formatKey(dialogData.value.key)
  };
  if (dialogMode.value === 'add') {
    const { data } = await axios.post('/api/admin/custom-placeholders', payload, apiKeyHeader());
    rows.value.push(data);
  } else if (dialogMode.value === 'edit' && editId) {
    const { data } = await axios.put(`/api/admin/custom-placeholders/${editId}`, payload, apiKeyHeader());
    const idx = rows.value.findIndex(r => r.id === editId);
    if (idx !== -1) rows.value[idx] = data;
  }
  showDialog.value = false;
}

async function onDelete(row) {
  await axios.delete(`/api/admin/custom-placeholders/${row.id}`, apiKeyHeader());
  rows.value = rows.value.filter(r => r.id !== row.id);
}

onMounted(fetchRows);
</script>
