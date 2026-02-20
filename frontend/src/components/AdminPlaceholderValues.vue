<script setup>
import { ref, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const columns = [
  { key: 'key', label: tr('admin_placeholder_values_columns_key'), sortable: true },
  { key: 'value', label: tr('admin_placeholder_values_columns_value'), sortable: false },
]

const rows = ref([])
const loading = ref(false)

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function fetchData() {
  loading.value = true
  try {
    const res = await fetchWithAuth('placeholders/values')
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    
    // Transform the placeholder data for display
    rows.value = Object.entries(data).map(([key, value]) => ({
      key,
      value: String(value ?? ''),
    }))
  } catch (e) {
    console.error('Error fetching placeholders:', e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchData()
})
</script>

<template>
  <AdminDataTable
    :columns="columns"
    :rows="rows"
    :loading="loading"
    :editable="false"
    :deletable="false"
    :creatable="false"
    :title="tr('admin_placeholder_values_title')"
  />
</template>
