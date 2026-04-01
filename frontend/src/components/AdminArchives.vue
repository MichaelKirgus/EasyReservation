<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import IconButton from './IconButton.vue'
import AdminDataTable from './AdminDataTable.vue'
import { adminFetch } from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const apiBase = import.meta.env.VITE_API_BASE || '/api'
const { tr } = useTranslation()
const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref('admin')
const currentUser = ref(JSON.parse(localStorage.getItem('admin_user') || 'null'))
const isSuperAdmin = computed(() => currentUser.value?.role === 'superadmin')
const archives = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')

// Anonymize dialog
const showAnonymizeDialog = ref(false)
const anonymizeFields = ref([])
const anonymizeScope = ref('all')

// Per-entry anonymize dialog
const anonymizeEntryTarget = ref(null) // { id, type: 'reservation'|'waitlist' }
const anonymizeEntryFields = ref([])

// Create archive dialog
const showCreateDialog = ref(false)
const newArchiveName = ref('')
const newArchiveDescription = ref('')
const newArchiveStoreEmails = ref(true)

// View state - active tab is 'list' or an archive ID
const activeTab = ref('list')
const viewTab = ref('reservations') // 'reservations' or 'waitlist'
const archiveReservations = ref([])
const archiveWaitlistEntries = ref([])
const selectedArchiveReservations = ref([])
const selectedArchiveWaitlistEntries = ref([])

// Filter state
const nameFilter = ref('')
const statusFilter = ref('')

const columns = computed(() => [
  { key: 'name', label: tr('admin_archives_column_name'), sortable: true },
  { key: 'description', label: tr('admin_archives_column_description'), sortable: false },
  { key: 'store_emails', label: tr('admin_archives_column_store_emails'), sortable: true, type: 'boolean' },
  { key: 'created_at', label: tr('admin_archives_column_created_at'), sortable: true },
])

const reservationColumns = computed(() => [
  { key: 'original_reservation_id', label: tr('admin_archives_column_original_id'), sortable: true, hidden: true },
  { key: 'display_name', label: tr('admin_archives_column_name'), sortable: true },
  { key: 'email', label: tr('admin_archives_column_email'), sortable: true },
  { key: 'date_added', label: tr('admin_archives_column_date'), sortable: true },
])

const waitlistColumns = computed(() => [
  { key: 'original_waitlist_entry_id', label: tr('admin_archives_column_original_id'), sortable: true, hidden: true },
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
  if (!apiKey.value || !archiveId) return
  
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
    
    let archiveData
    try {
      archiveData = await res.json()
    } catch (e) {
      // If JSON parsing fails, use the text response for error handling
      const text = await res.text()
      if (!res.ok) throw new Error(text)
      throw e
    }
    
    if (!res.ok) throw new Error(archiveData.message || 'Unknown error')
    setMessage(tr('admin_archives_created_success'))
    
    // Archive current data to the newly created archive
    loading.value = true
    try {
      await fetchWithAuth(`archives/${archiveData.id}/archive-data`, { method: 'POST' })
      setMessage(tr('admin_archives_data_archived_success'))
    } catch (e) {
      setError(tr('error_archiving_data') + ': ' + e)
    }
    
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

async function archiveCurrentData(archiveId) {
  if (!archiveId) return
  
  if (!confirm(tr('admin_archives_confirm_data_archive'))) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${archiveId}/archive-data`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_data_archived_success'))
    // Refresh the archive data
    await loadArchiveData(archiveId, viewTab.value)
  } catch (e) {
    setError(tr('error_archiving_data') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function viewArchive(archive) {
  activeTab.value = archive.id
  viewTab.value = 'reservations'
  nameFilter.value = ''
  statusFilter.value = ''
  archiveReservations.value = []
  archiveWaitlistEntries.value = []
  selectedArchiveReservations.value = []
  selectedArchiveWaitlistEntries.value = []
}

function closeArchiveView() {
  activeTab.value = 'list'
}

async function restoreReservation(reservationId) {
  if (!activeTab.value || !reservationId) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeTab.value}/restore-reservation/${reservationId}`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_restored_success'))
    // Refresh the archive data
    await loadArchiveData(activeTab.value, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function bulkRestoreReservations() {
  if (!selectedArchiveReservations.value.length || !activeTab.value) return
  
  if (!confirm(tr('admin_archives_bulk_restore_confirm', { count: selectedArchiveReservations.value.length }))) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeTab.value}/restore-reservations`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ reservation_ids: selectedArchiveReservations.value }),
    })
    
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_bulk_restored_success'))
    selectedArchiveReservations.value = []
    await loadArchiveData(activeTab.value, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function restoreWaitlistEntry(entryId) {
  if (!activeTab.value || !entryId) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeTab.value}/restore-waitlist-entry/${entryId}`, { method: 'POST' })
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_restored_success'))
    await loadArchiveData(activeTab.value, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

async function bulkRestoreWaitlistEntries() {
  if (!selectedArchiveWaitlistEntries.value.length || !activeTab.value) return
  
  if (!confirm(tr('admin_archives_bulk_restore_confirm', { count: selectedArchiveWaitlistEntries.value.length }))) return
  
  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeTab.value}/restore-waitlist-entries`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entry_ids: selectedArchiveWaitlistEntries.value }),
    })
    
    const text = await res.text()
    if (!res.ok) throw new Error(text)
    
    setMessage(tr('admin_archives_bulk_restored_success'))
    selectedArchiveWaitlistEntries.value = []
    await loadArchiveData(activeTab.value, viewTab.value)
  } catch (e) {
    setError(tr('error_restoring') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function downloadCsv(type) {
  if (!activeTab.value) return
  
  const url = `${apiBase}/${routePrefix.value}/archives/${activeTab.value}/download-csv/${type}`
  window.open(url, '_blank')
}

async function anonymizeData() {
  if (!anonymizeFields.value.length) {
    setError(tr('admin_archives_anonymize_fields_required'))
    return
  }
  if (!confirm(tr('admin_archives_anonymize_confirm'))) return

  loading.value = true
  try {
    const res = await fetchWithAuth(`archives/${activeTab.value}/anonymize`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fields: anonymizeFields.value, scope: anonymizeScope.value }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)

    setMessage(tr('admin_archives_anonymized_success'))
    showAnonymizeDialog.value = false
    anonymizeFields.value = []
    anonymizeScope.value = 'all'
    await loadArchiveData(activeTab.value, viewTab.value)
  } catch (e) {
    setError(tr('error_anonymizing') + ': ' + e)
  } finally {
    loading.value = false
  }
}

function openAnonymizeEntry(id, type) {
  anonymizeEntryTarget.value = { id, type }
  anonymizeEntryFields.value = []
}

async function anonymizeEntry() {
  if (!anonymizeEntryFields.value.length) {
    setError(tr('admin_archives_anonymize_fields_required'))
    return
  }
  if (!confirm(tr('admin_archives_anonymize_confirm'))) return

  const { id, type } = anonymizeEntryTarget.value
  const endpoint = type === 'reservation'
    ? `archives/${activeTab.value}/anonymize-reservation/${id}`
    : `archives/${activeTab.value}/anonymize-waitlist-entry/${id}`

  loading.value = true
  try {
    const res = await fetchWithAuth(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fields: anonymizeEntryFields.value }),
    })
    const text = await res.text()
    if (!res.ok) throw new Error(text)

    setMessage(tr('admin_archives_anonymized_success'))
    anonymizeEntryTarget.value = null
    anonymizeEntryFields.value = []
    await loadArchiveData(activeTab.value, viewTab.value)
  } catch (e) {
    setError(tr('error_anonymizing') + ': ' + e)
  } finally {
    loading.value = false
  }
}

watch(() => activeTab.value, (newId) => {
  if (newId && newId !== 'list') {
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
        v-if="activeTab === 'list'"
        icon="plus"
        variant="success"
        :label="tr('admin_archives_create_button')"
        @click="showCreateDialog = true"
      />
    </div>

    <!-- Message/Error -->
    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <!-- Create Archive Dialog (Modal) -->
    <div v-if="showCreateDialog" class="modal-backdrop" @click.self="showCreateDialog = false">
      <div class="modal">
        <h3>{{ tr('admin_archives_create_title') }}</h3>
        
        <div class="field">
          <label>{{ tr('admin_archives_name_label') }}</label>
          <input
            v-model="newArchiveName"
            :placeholder="tr('admin_archives_name_placeholder')"
          />
        </div>

        <div class="field">
          <label>{{ tr('admin_archives_description_label') }}</label>
          <textarea
            v-model="newArchiveDescription"
            :placeholder="tr('admin_archives_description_placeholder')"
            rows="3"
          />
        </div>

        <div class="field">
          <label class="checkbox-label">
            <input
              type="checkbox"
              v-model="newArchiveStoreEmails"
            />
            {{ tr('admin_archives_store_emails_label') }}
          </label>
        </div>

        <div class="modal-actions">
          <IconButton class="ghost" variant="ghost" @click="showCreateDialog = false" icon="close" :label="tr('cancel')" />
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

    <!-- Archive List View -->
    <AdminDataTable
      v-if="activeTab === 'list'"
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
          icon="archive"
          :label="tr('admin_archives_archive_data')"
          @click.stop="archiveCurrentData(row.id)"
        />
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

    <!-- Archive Details View -->
    <div v-else class="archive-details">
      <!-- Back button and header -->
      <div class="details-header">
        <IconButton
          icon="chevronLeft"
          :label="tr('admin_archives_back')"
          @click="closeArchiveView"
        />
        <h3>{{ tr('admin_archives_view_title') }}: {{ archives.find(a => a.id === activeTab)?.name }}</h3>
      </div>

      <!-- Tabs -->
      <div class="admin-tabs">
        <button
          class="admin-tab-button"
          :class="{ active: viewTab === 'reservations' }"
          @click="viewTab = 'reservations'"
        >
          {{ tr('admin_archives_tab_reservations') }}
        </button>
        <button
          class="admin-tab-button"
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
          @keyup.enter="loadArchiveData(activeTab, viewTab)"
        />
        <select
          v-if="viewTab === 'waitlist'"
          v-model="statusFilter"
          @change="loadArchiveData(activeTab, viewTab)"
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

        <IconButton
          v-if="isSuperAdmin"
          icon="trash"
          variant="danger"
          :label="tr('admin_archives_anonymize_button')"
          @click="showAnonymizeDialog = true"
        />
      </div>

      <!-- Anonymize Dialog (superadmin only) -->
      <div v-if="showAnonymizeDialog && isSuperAdmin" class="modal-backdrop" @click.self="showAnonymizeDialog = false">
        <div class="modal">
          <h3>{{ tr('admin_archives_anonymize_title') }}</h3>

          <div class="field">
            <label>{{ tr('admin_archives_anonymize_fields') }}</label>
            <label class="checkbox-label">
              <input type="checkbox" value="email" v-model="anonymizeFields" />
              {{ tr('admin_archives_anonymize_email') }}
            </label>
            <label class="checkbox-label">
              <input type="checkbox" value="name" v-model="anonymizeFields" />
              {{ tr('admin_archives_anonymize_name') }}
            </label>
          </div>

          <div class="field">
            <label>{{ tr('admin_archives_anonymize_scope') }}</label>
            <label class="checkbox-label">
              <input type="radio" value="reservations" v-model="anonymizeScope" />
              {{ tr('admin_archives_anonymize_scope_reservations') }}
            </label>
            <label class="checkbox-label">
              <input type="radio" value="waitlist" v-model="anonymizeScope" />
              {{ tr('admin_archives_anonymize_scope_waitlist') }}
            </label>
            <label class="checkbox-label">
              <input type="radio" value="all" v-model="anonymizeScope" />
              {{ tr('admin_archives_anonymize_scope_all') }}
            </label>
          </div>

          <div class="modal-actions">
            <IconButton class="ghost" variant="ghost" @click="showAnonymizeDialog = false" icon="close" :label="tr('cancel')" />
            <button
              class="btn-danger"
              @click="anonymizeData"
              :disabled="loading || !anonymizeFields.length"
            >
              {{ tr('admin_archives_anonymize_button') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Data Table -->
      <AdminDataTable
        v-if="viewTab === 'reservations'"
        :columns="reservationColumns"
        :rows="archiveReservations"
        :loading="loading"
        :page-size="20"
        persist-key="admin-archives-reservations"
        @refresh="loadArchiveData(activeTab, viewTab)"
        :empty-text="tr('admin_archives_no_data')"
      >
        <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
        <template #row-actions="{ row }">
          <IconButton
            icon="restore"
            :label="tr('admin_archives_restore')"
            @click.stop="restoreReservation(row.id)"
          />
          <IconButton
            v-if="isSuperAdmin"
            icon="trash"
            variant="danger"
            :label="tr('admin_archives_anonymize_entry')"
            @click.stop="openAnonymizeEntry(row.id, 'reservation')"
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
        @refresh="loadArchiveData(activeTab, viewTab)"
        :empty-text="tr('admin_archives_no_data')"
      >
        <template #cell-date_added="{ value }">{{ formatDateTime(value) }}</template>
        <template #row-actions="{ row }">
          <IconButton
            icon="restore"
            :label="tr('admin_archives_restore')"
            @click.stop="restoreWaitlistEntry(row.id)"
          />
          <IconButton
            v-if="isSuperAdmin"
            icon="trash"
            variant="danger"
            :label="tr('admin_archives_anonymize_entry')"
            @click.stop="openAnonymizeEntry(row.id, 'waitlist')"
          />
        </template>
      </AdminDataTable>
    </div>

    <!-- Per-entry Anonymize Dialog (superadmin only) -->
    <div v-if="anonymizeEntryTarget && isSuperAdmin" class="modal-backdrop" @click.self="anonymizeEntryTarget = null">
      <div class="modal">
        <h3>{{ tr('admin_archives_anonymize_entry_title') }}</h3>

        <div class="field">
          <label>{{ tr('admin_archives_anonymize_fields') }}</label>
          <label class="checkbox-label">
            <input type="checkbox" value="email" v-model="anonymizeEntryFields" />
            {{ tr('admin_archives_anonymize_email') }}
          </label>
          <label class="checkbox-label">
            <input type="checkbox" value="name" v-model="anonymizeEntryFields" />
            {{ tr('admin_archives_anonymize_name') }}
          </label>
        </div>

        <div class="modal-actions">
          <IconButton class="ghost" variant="ghost" @click="anonymizeEntryTarget = null" icon="close" :label="tr('cancel')" />
          <button
            class="btn-danger"
            @click="anonymizeEntry"
            :disabled="loading || !anonymizeEntryFields.length"
          >
            {{ tr('admin_archives_anonymize_button') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.75rem; width: 100%; }
.top-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }

.message { color: #065f46; background: #ecfdf3; border: 1px solid #a7f3d0; padding: 0.5rem; border-radius: 6px; }
.error { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; }

/* Modal */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 50;
}

.modal {
  background: var(--app-card-bg, var(--surface));
  color: var(--text);
  border-radius: 12px;
  padding: 1rem;
  width: min(720px, 100%);
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 50px var(--shadow);
  border: 1px solid var(--border-strong);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

/* Archive Details View */
.archive-details { display: flex; flex-direction: column; gap: 1rem; }

.details-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.tabs { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; }
.tabs button {
  padding: 0.5rem 1rem;
  border: 1px solid #d1d5db;
  background: #f8fafc;
  border-radius: 6px;
  cursor: pointer;
}

.archive-actions { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; }

/* Field styling */
.field { display: flex; flex-direction: column; gap: 0.25rem; }
.field label {
  font-weight: 600;
}
.field input, .field textarea {
  padding: 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}

/* Modal Actions */
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }

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
