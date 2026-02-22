<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const { tr } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref(localStorage.getItem('admin_route_prefix') || 'admin')
const archives = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

// Create archive dialog
const showCreateDialog = ref(false)
const newArchiveName = ref('')
const newArchiveDescription = ref('')
const newArchiveStoreEmails = ref(true)

// View archive dialog
const activeArchive = ref(null)
const viewTab = ref('reservations') // 'reservations' or 'waitlist'
const archiveReservations = ref([])
const archiveWaitlistEntries = ref([])
const selectedArchiveReservations = ref([])
const selectedArchiveWaitlistEntries = ref([])

// Filter state
const nameFilter = ref('')
const statusFilter = ref('')

const columns = computed(() => [
  { key: 'id', label: tr('admin_archives_column_id'), sortable: true },
  { key: 'name', label: tr('admin_archives_column_name'), sortable: true },
  { key: 'description', label: tr('admin_archives_column_description'), sortable: false },
  { key: 'store_emails', label: tr('admin_archives_column_store_emails'), sortable: true, type: 'boolean' },
  { key: 'created_at', label: tr('admin_archives_column_created_at'), sortable: true },
  { key: 'actions', label: tr('admin_archives_column_actions'), sortable: false },
])

const reservationColumns = computed(() => [
  { key: 'id', label: tr('admin_archives_column_id'), sortable: true },
  { key: 'original_reservation_id', label: tr('admin_archives_column_original_id'), sortable: true },
  { key: 'display_name', label: tr('admin_archives_column_name'), sortable: true },
  { key: 'email', label: tr('admin_archives_column_email'), sortable: true },
  { key: 'date_added', label: tr('admin_archives_column_date'), sortable: true },
])

const waitlistColumns = computed(() => [
  { key: 'id', label: tr('admin_archives_column_id'), sortable: true },
  { key: 'original_waitlist_entry_id', label: tr('admin_archives_column_original_id'), sortable: true },
  { key: 'display_name', label: tr('admin_archives_column_name'), sortable: true },
  { key: 'email', label: tr('admin_archives_column_email'), sortable: true },
  { key: 'status', label: tr('admin_archives_column_status'), sortable: true },
  { key: 'date_added', label: tr('admin_archives_column_date'), sortable: true },
])

function formatDateTime(val) {
  if (!val) return ''
  const d = new Date(val)
  if (Number.isNaN(d.getTime())) return val
  return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(d)
}

function setMessage(msg) { message.value = msg; error.value = '' }
function setError(msg, opts = {}) {
  if (opts.auto) {
    const now = Date.now()
    if (now - lastAutoErrorAt.value < 30000) return
    lastAutoErrorAt.value = now
  }
  error.value = msg
  message.value = ''
}

const fetchWithAuth = (relative, opts = {}) => adminFetch(relative, opts, { apiKeyRef: apiKey, routePrefixRef: routePrefix })

async function loadArchives(opts = {}) {
  if (!apiKey.value) { setError(tr('please_login_api_key_missing'), opts); return }
  loading.value = true
  try {
    const res = await fetchWithAuth('archives')
    if (!res.ok) throw new Error(await res.text())
    archives.value = await res.json()
    if (!opts.auto) setMessage('')
  } catch (e) {
    setError(tr('error_loading') + ': ' + e, opts)
  } finally {
    loading.value = false
  }
}

async function loadArchiveData(archiveId, tab) {
  if (!apiKey.value || !activeArchive.value) return
  
  if (tab === 'reservations') {
    try {
      const res = await fetchWithAuth(`archives/${archiveId}/reservations?name=${encodeURIComponent(nameFilter.value)}`)
      if (!res.ok) throw new Error(await res.text())
      archiveReservations.value = await res.json()
    } catch (e) {
      setError(tr('error_loading') + ': ' + e)
    }
  } else {
    try {
      const res = await fetchWithAuth(`archives/${archiveId}/waitlist?name=${encodeURIComponent(nameFilter.value)}&status=${encodeURIComponent(statusFilter.value)}`)
      if (!res.ok) throw new Error(await res.text())
      archiveWaitlistEntries.value = await res.json()
    } catch (e) {
      setError(tr('error_loading') + ': ' + e)
    }
  }
}

async function createArchive() {
  if (!newArchiveName.value.trim()) {
    setError(tr('admin_archives_name_required'))
    return
  }

  loading.value = true
  try {
    const res = await fetchWithAuth('archives', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: newArchiveName.value.trim(),
        description: newArchiveDescription.value.trim() || null,
        store_emails: newArchiveStoreEmails.value,
      }),
    })
    
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_created_success'))
    showCreateDialog.value = false
    newArchiveName.value = ''
    newArchiveDescription.value = ''
    newArchiveStoreEmails.value = true
    await loadArchives()
  } catch (e) {
    setError(e.message || tr('error_creating_archive'))
  } finally {
    loading.value = false
  }
}

async function deleteArchive(archiveId) {
  if (!confirm(tr('admin_archives_delete_confirm'))) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${archiveId}`, { method: 'DELETE' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_deleted_success'))
    archives.value = archives.value.filter(a => a.id !== archiveId)
  } catch (e) {
    setError(tr('error_deleting_archive') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function viewArchive(archive) {
  activeArchive.value = archive
  viewTab.value = 'reservations'
  nameFilter.value = ''
  statusFilter.value = ''
  archiveReservations.value = []
  archiveWaitlistEntries.value = []
  selectedArchiveReservations.value = []
  selectedArchiveWaitlistEntries.value = []
}

function closeArchiveView() {
  activeArchive.value = null
}

async function restoreReservation(reservationId) {
  if (!activeArchive.value || !reservationId) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeArchive.value.id}/restore-reservation/${reservationId}`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_restored_success'))
    // Refresh the archive data
    await loadArchiveData(activeArchive.value.id, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function bulkRestoreReservations() {
  if (!selectedArchiveReservations.value.length || !activeArchive.value) return
  
  if (!confirm(tr('admin_archives_bulk_restore_confirm', { count: selectedArchiveReservations.value.length }))) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeArchive.value.id}/restore-reservations`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reservation_ids: selectedArchiveReservations.value }),
    })
    
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_bulk_restored_success'))
    selectedArchiveReservations.value = []
    await loadArchiveData(activeArchive.value.id, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function restoreWaitlistEntry(entryId) {
  if (!activeArchive.value || !entryId) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeArchive.value.id}/restore-waitlist-entry/${entryId}`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_restored_success'))
    await loadArchiveData(activeArchive.value.id, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function bulkRestoreWaitlistEntries() {
  if (!selectedArchiveWaitlistEntries.value.length || !activeArchive.value) return
  
  if (!confirm(tr('admin_archives_bulk_restore_confirm', { count: selectedArchiveWaitlistEntries.value.length }))) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeArchive.value.id}/restore-waitlist-entries`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entry_ids: selectedArchiveWaitlistEntries.value }),
    })
    
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_bulk_restored_success'))
    selectedArchiveWaitlistEntries.value = []
    await loadArchiveData(activeArchive.value.id, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function downloadCsv(type) {
  if (!activeArchive.value) return
  
  const url = `${apiBase}/${routePrefix.value}/archives/${activeArchive.value.id}/download-csv/${type}`
  window.open(url, '_blank')
}

watch(() => activeArchive.value?.id, (newId) => {
  if (newId) {
    loadArchiveData(newId, viewTab.value)
  }
})

onMounted(() => {
  loadArchives()
})
</script>

<template>
  <div class="stack">
    <!-- Header -->
    <div class="top-bar">
      <h2>{{ tr('admin_archives_title') }}</h2>
      <IconButton
        icon="plus"
        variant="success"
        :label="tr('admin_archives_create_button')"
        @click="showCreateDialog = true"
      />
    </div>

    <!-- Message/Error -->
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <!-- Archives List -->
    <AdminDataTable
      :columns="columns"
      :rows="archives"
      :loading="loading"
      :page-size="20"
      persist-key="admin-archives"
      @refresh="loadArchives"
      :empty-text="tr('admin_archives_no_archives')"
    >
      <template #cell-store_emails="{ value }">
        <span v-if="value" class="label inline success">{{ tr('yes') }}</span>
        <span v-else class="label inline danger">{{ tr('no') }}</span>
      </template>
      <template #row-actions="{ row }">
        <IconButton
          icon="eye"
          :label="tr('admin_archives_view')"
          @click.stop="viewArchive(row)"
        />
        <IconButton
          v-if="routePrefix === 'admin'"
          icon="trash"
          variant="danger"
          :label="tr('admin_archives_delete')"
          @click.stop="deleteArchive(row.id)"
        />
      </template>
    </AdminDataTable>

    <!-- Create Archive Dialog -->
    <div v-if="showCreateDialog" class="modal-overlay">
      <div class="modal">
        <h3>{{ tr('admin_archives_create_title') }}</h3>
        
        <div class="form-group">
          <label>{{ tr('admin_archives_name_label') }}</label>
          <input
            v-model="newArchiveName"
            :placeholder="tr('admin_archives_name_placeholder')"
          />
        </div>

        <div class="form-group">
          <label>{{ tr('admin_archives_description_label') }}</label>
          <textarea
            v-model="newArchiveDescription"
            :placeholder="tr('admin_archives_description_placeholder')"
            rows="3"
          />
        </div>

        <div class="form-group">
          <label class="checkbox-label">
            <input
              type="checkbox"
              v-model="newArchiveStoreEmails"
            />
            {{ tr('admin_archives_store_emails_label') }}
          </label>
        </div>

        <div class="modal-actions">
          <button @click="showCreateDialog = false">{{ tr('cancel') }}</button>
          <button
            variant="success"
            @click="createArchive"
            :disabled="loading || !newArchiveName.trim()"
          >
            {{ tr('admin_archives_create_button') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Archive View Modal -->
    <div v-if="activeArchive" class="modal-overlay">
      <div class="modal large">
        <div class="modal-header">
          <h3>{{ tr('admin_archives_view_title') }}: {{ activeArchive.name }}</h3>
          <button class="close-btn" @click="closeArchiveView">&times;</button>
        </div>

        <!-- Tabs -->
        <div class="tabs">
          <button
            :class="{ active: viewTab === 'reservations' }"
            @click="viewTab = 'reservations'"
          >
            {{ tr('admin_archives_tab_reservations') }}
          </button>
          <button
            :class="{ active: viewTab === 'waitlist' }"
            @click="viewTab = 'waitlist'"
          >
            {{ tr('admin_archives_tab_waitlist') }}
          </button>
        </div>

        <!-- Filters -->
        <div class="filters">
          <input
            v-model="nameFilter"
            :placeholder="tr('admin_archives_filter_name')"
            @keyup.enter="loadArchiveData(activeArchive.id, viewTab)"
          />
          <select
            v-if="viewTab === 'waitlist'"
            v-model="statusFilter"
            @change="loadArchiveData(activeArchive.id, viewTab)"
          >
            <option value="">{{ tr('admin_archives_status_all') }}</option>
            <option value="pending">{{ tr('admin_archives_status_pending') }}</option>
            <option value="promoted">{{ tr('admin_archives_status_promoted') }}</option>
            <option value="cancelled">{{ tr('admin_archives_status_cancelled') }}</option>
          </select>
        </div>

        <!-- Actions -->
        <div class="archive-actions">
          <IconButton
            icon="download"
            :label="tr('admin_archives_download_csv')"
            @click="downloadCsv(viewTab === 'reservations' ? 'reservations' : 'waitlist')"
          />
          
          <template v-if="viewTab === 'reservations'">
            <IconButton
              icon="restore"
              :label="tr('admin_archives_restore_selection')"
              @click="bulkRestoreReservations"
              :disabled="!selectedArchiveReservations.length"
            />
          </template>
          
          <template v-else>
            <IconButton
              icon="restore"
              :label="tr('admin_archives_restore_selection')"
              @click="bulkRestoreWaitlistEntries"
              :disabled="!selectedArchiveWaitlistEntries.length"
            />
          </template>
        </div>

        <!-- Data Table -->
        <AdminDataTable
          v-if="viewTab === 'reservations'"
          :columns="reservationColumns"
          :rows="archiveReservations"
          :loading="loading"
          :page-size="20"
          persist-key="admin-archives-reservations"
          @refresh="loadArchiveData(activeArchive.id, viewTab)"
          :empty-text="tr('admin_archives_no_data')"
        >
          <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
          <template #row-actions="{ row }">
            <IconButton
              icon="restore"
              :label="tr('admin_archives_restore')"
              @click.stop="restoreReservation(row.id)"
            />
          </template>
        </AdminDataTable>

        <AdminDataTable
          v-else
          :columns="waitlistColumns"
          :rows="archiveWaitlistEntries"
          :loading="loading"
          :page-size="20"
          persist-key="admin-archives-waitlist"
          @refresh="loadArchiveData(activeArchive.id, viewTab)"
          :empty-text="tr('admin_archives_no_data')"
        >
          <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
          <template #row-actions="{ row }">
            <IconButton
              icon="restore"
              :label="tr('admin_archives_restore')"
              @click.stop="restoreWaitlistEntry(row.id)"
            />
          </template>
        </AdminDataTable>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; }
.top-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }

.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal {
  background: #fff;
  border-radius: 8px;
  padding: 1.5rem;
  max-width: 500px;
  width: 90%;
  max-height: 90vh;
  overflow-y: auto;
}

.modal.large { max-width: 900px; }

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: #6b7280;
}

/* Tabs */
.tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; }
.tabs button {
  background: none;
  border: none;
  padding: 0.5rem 1rem;
  cursor: pointer;
  color: #6b7280;
  font-weight: 500;
}
.tabs button.active { color: #2563eb; border-bottom: 2px solid #2563eb; }

/* Filters */
.filters { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
.filters input, .filters select {
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

/* Archive Actions */
.archive-actions { display: flex; gap: 0.5rem; margin-bottom: 1rem; }

/* Form Groups */
.form-group { margin-bottom: 1rem; }
.form-group label {
  display: block;
  font-weight: 600;
  margin-bottom: 0.25rem;
}
.form-group input, .form-group textarea {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

/* Modal Actions */
.modal-actions { display: flex; gap: 0.5rem; justify-content: flex-end; }
.modal-actions button {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}
.modal-actions button[type="button"] { background: #e5e7eb; color: #374151; }
.modal-actions button.success { background: #22c55e; color: white; }

/* Labels */
.label.inline {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  font-size: 0.875rem;
}
.label.success { background: #dcfce7; color: #166534; }
.label.danger { background: #fee2e2; color: #991b1b; }

/* Checkbox Label */
.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: normal;
}
.checkbox-label input { width: auto; }
</style>
