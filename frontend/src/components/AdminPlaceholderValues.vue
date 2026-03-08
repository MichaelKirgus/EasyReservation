<script setup>
import { ref, computed, onMounted } from 'vue'
import AdminDataTable from './AdminDataTable.vue'
import SecretField from './SecretField.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

// Columns - defined as computed to ensure translations are loaded
const columns = computed(() => [
  { key: 'key', label: tr('admin_placeholder_values_columns_key'), sortable: true },
  { key: 'value', label: tr('admin_placeholder_values_columns_value'), sortable: false },
])

const rows = ref([])
const loading = ref(false)

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function fetchData() {
  loading.value = true
  try {
    // Fetch all placeholders from PlaceholderService (system + custom)
    const res = await fetchWithAuth('placeholders/values')
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    
    // Fetch custom placeholder types to determine which values should use SecretField
    const customRes = await fetchWithAuth('custom-placeholders')
    if (customRes.ok) {
      const customData = await customRes.json()
      // Create a map of custom placeholder keys to their type
      const customTypeMap = {}
      for (const item of customData) {
        customTypeMap[item.key] = item.type
      }
      
      // Transform the placeholder data for display with type info
      rows.value = Object.entries(data).map(([key, value]) => ({
        key,
        value: String(value ?? ''),
        isSecretCustomPlaceholder: customTypeMap[key] === 'secret',
      }))
    } else {
      // If custom placeholders API fails, fallback to plain text for all
      rows.value = Object.entries(data).map(([key, value]) => ({
        key,
        value: String(value ?? ''),
        isSecretCustomPlaceholder: false,
      }))
    }
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
  >
    <template #cell-value="{ row }">
      <!-- Use SecretField only for secret custom placeholders -->
      <!-- System placeholders and generic custom placeholders show as plain text -->
      <span v-if="!row.isSecretCustomPlaceholder">{{ row.value }}</span>
      <SecretField v-else v-model="row.value" />
    </template>
  </AdminDataTable>
</template>
