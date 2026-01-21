
<template>
  <div class="card">
    <div class="card-header">
      <h4>Redis-Keys</h4>
    </div>
    <p v-if="error" class="error">{{ error }}</p>
    <AdminDataTable
      :columns="columns"
      :rows="keys"
      :loading="loading"
      empty-text="Keine Keys vorhanden."
      @refresh="loadKeys"
    >
      <template #cell-actions="{ row }">
        <IconButton icon="trash" label="Löschen" class="ghost" @click="deleteKey(row)" />
      </template>
    </AdminDataTable>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'

const props = defineProps({
  apiBase: { type: String, required: true },
  apiKey: { type: String, required: true }
})

const loading = ref(false)
const error = ref('')
const keys = ref([])
const columns = [
  { key: 'key', label: 'Key', sortable: true },
  { key: 'type', label: 'Typ', sortable: true },
  { key: 'length', label: 'Länge', sortable: true },
  { key: 'actions', label: 'Aktionen' }
]

async function loadKeys() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch(`${props.apiBase}/admin/diagnostics/redis-keys`, {
      headers: { 'X-Api-Key': props.apiKey }
    })
    const data = await res.json()
    if (data.error) throw new Error(data.error)
    keys.value = data.keys || []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function deleteKey(row) {
  if (!confirm(`Key wirklich löschen?\n${row.key}`)) return
  loading.value = true
  try {
    const res = await fetch(`${props.apiBase}/admin/diagnostics/redis-keys`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json', 'X-Api-Key': props.apiKey },
      body: JSON.stringify({ key: row.key })
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error || 'Fehler beim Löschen')
    await loadKeys()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(loadKeys)
</script>
