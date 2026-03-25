<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import IconButton from './IconButton.vue'
import SecretField from './SecretField.vue'
import AdminDataTable from './AdminDataTable.vue'
import {
  getDataPortabilityTransportProfiles,
  createDataPortabilityTransportProfile,
  updateDataPortabilityTransportProfile,
  deleteDataPortabilityTransportProfile,
  getDataPortabilityTables,
  getDataPortabilityFiles,
  uploadDataPortabilityFile,
  downloadDataPortabilityFile,
  createDataPortabilityBackupOperation,
  createDataPortabilityRestoreOperation,
  preflightDataPortabilityTransport,
  createDataPortabilityTransportOperation,
  getDataPortabilityOperation,
  getDataPortabilityOperations,
} from '../utils/adminApi'
import { useTranslation } from '../composables/useTranslation'

const { tr } = useTranslation()

const apiKey = ref(localStorage.getItem('admin_auth_session') || sessionStorage.getItem('admin_auth_session') || '')
const routePrefix = ref('admin')

const selectedTab = ref('profiles')
const loading = ref(false)
const message = ref('')
const error = ref('')

const profiles = ref([])
const editingProfile = ref(null)
const showProfileForm = ref(false)

const tables = ref([])
const backupFiles = ref([])
const selectedTables = ref([])
const preflightResult = ref(null)
const backupSelectedTables = ref([])
const restoreSelectedTables = ref([])
const transportSelectedTables = ref([])
const selectedTransportProfileId = ref(null)
const selectedRestoreMode = ref('truncate_insert')
const selectedRestoreSourcePath = ref('')
const selectedUploadFile = ref(null)
const operations = ref([])
const currentOperation = ref(null)
const operationPolling = ref(false)
const showOperationDetails = ref(false)
const detailLoading = ref(false)
const operationDetails = ref(null)
let pollingTimer = null

const profileForm = reactive({
  name: '',
  target_base_url: '',
  target_api_token: '',
  timeout_seconds: 120,
  is_active: true,
})

const tabs = computed(() => [
  { id: 'profiles', label: tr('admin_data_portability_profiles_tab', 'Transport Profiles') },
  { id: 'backup', label: tr('admin_data_portability_backup_tab', 'Create Backup') },
  { id: 'files', label: tr('admin_data_portability_files_tab', 'Backup Files') },
  { id: 'restore', label: tr('admin_data_portability_restore_tab', 'Run Restore') },
  { id: 'preflight', label: tr('admin_data_portability_preflight_tab', 'Preflight Check') },
  { id: 'transport', label: tr('admin_data_portability_transport_tab', 'Run Transport') },
])

const profileColumns = computed(() => [
  { key: 'id', label: tr('admin_data_portability_profile_id', 'ID'), sortable: true },
  { key: 'name', label: tr('admin_data_portability_profile_name', 'Name'), sortable: true },
  { key: 'target_base_url', label: tr('admin_data_portability_profile_target_url', 'Target URL'), sortable: true },
  { key: 'timeout_seconds', label: tr('admin_data_portability_profile_timeout', 'Timeout (s)'), sortable: true },
  { key: 'is_active', label: tr('admin_data_portability_profile_active', 'Active'), sortable: true, type: 'boolean' },
])

const preflightRows = computed(() => {
  if (!preflightResult.value) return []

  const accepted = (preflightResult.value.accepted_tables || []).map((table) => ({
    table,
    status: tr('admin_data_portability_preflight_status_ok', 'available'),
  }))

  const missing = (preflightResult.value.missing_tables || []).map((table) => ({
    table,
    status: tr('admin_data_portability_preflight_status_missing', 'missing'),
  }))

  return [...accepted, ...missing]
})

const preflightColumns = computed(() => [
  { key: 'table', label: tr('admin_data_portability_table_name', 'Table'), sortable: true },
  { key: 'status', label: tr('admin_data_portability_table_status', 'Status'), sortable: true },
])

const operationColumns = computed(() => [
  { key: 'id', label: tr('admin_data_portability_operation_id', 'Operation ID'), sortable: true },
  { key: 'type', label: tr('admin_data_portability_operation_type', 'Type'), sortable: true },
  { key: 'status', label: tr('admin_data_portability_operation_status', 'Status'), sortable: true },
  { key: 'restore_mode', label: tr('admin_data_portability_operation_restore_mode', 'Restore Mode'), sortable: true },
  { key: 'created_at', label: tr('admin_data_portability_operation_created_at', 'Created'), sortable: true },
  { key: 'finished_at', label: tr('admin_data_portability_operation_finished_at', 'Finished'), sortable: true },
])

const fileColumns = computed(() => [
  { key: 'filename', label: tr('admin_data_portability_file_name', 'Filename'), sortable: true },
  { key: 'path', label: tr('admin_data_portability_file_path', 'Path'), sortable: true },
  { key: 'size_human', label: tr('admin_data_portability_file_size', 'Size'), sortable: true },
  { key: 'last_modified', label: tr('admin_data_portability_file_last_modified', 'Last Modified'), sortable: true },
])

const activeProfiles = computed(() => profiles.value.filter((p) => p.is_active))

const fileRows = computed(() => backupFiles.value.map((file) => ({
  ...file,
  size_human: formatBytes(Number(file.size_bytes || 0)),
})))

const visibleOperations = computed(() => {
  const typeMap = {
    backup: 'backup',
    restore: 'restore',
    transport: 'transport',
  }

  const wanted = typeMap[selectedTab.value]
  if (!wanted) return operations.value

  return operations.value.filter((row) => row.type === wanted)
})

const detailTransportResult = computed(() => operationDetails.value?.options?.transport_result || null)
const detailRestoreResult = computed(() => operationDetails.value?.options?.restore_result || null)

function normalizeStatus(status) {
  const raw = String(status || '').toLowerCase()
  if (raw === 'done') return 'completed'
  if (raw === 'success') return 'completed'
  if (raw === 'started') return 'running'
  return raw || 'queued'
}

function statusBadgeClass(status) {
  const normalized = normalizeStatus(status)
  if (normalized === 'completed') return 'status-badge completed'
  if (normalized === 'running') return 'status-badge running'
  if (normalized === 'failed') return 'status-badge failed'
  return 'status-badge queued'
}

function statusLabel(status) {
  return normalizeStatus(status)
}

function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'

  const units = ['B', 'KB', 'MB', 'GB']
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const value = bytes / (1024 ** index)

  return `${value.toFixed(value >= 10 || index === 0 ? 0 : 1)} ${units[index]}`
}

function adminConfig() {
  return { routePrefixRef: routePrefix, apiKeyRef: apiKey }
}

function setMessage(text) {
  message.value = text
  error.value = ''
}

function setError(text) {
  error.value = text
  message.value = ''
}

async function loadProfiles() {
  if (!apiKey.value) {
    setError(tr('please_login_api_key_missing', 'Please login first.'))
    return
  }

  loading.value = true
  try {
    const data = await getDataPortabilityTransportProfiles(adminConfig())
    profiles.value = Array.isArray(data?.profiles) ? data.profiles : []
  } catch (e) {
    setError(tr('admin_data_portability_error_loading_profiles', 'Error loading transport profiles: ') + e)
  } finally {
    loading.value = false
  }
}

async function loadTables() {
  if (!apiKey.value) return

  try {
    const data = await getDataPortabilityTables(adminConfig())
    tables.value = Array.isArray(data?.tables) ? data.tables : []
  } catch (e) {
    setError(tr('admin_data_portability_error_loading_tables', 'Error loading tables: ') + e)
  }
}

async function loadBackupFiles() {
  if (!apiKey.value) return

  try {
    const data = await getDataPortabilityFiles(adminConfig())
    backupFiles.value = Array.isArray(data?.files) ? data.files : []
  } catch (e) {
    setError(tr('admin_data_portability_error_loading_files', 'Error loading backup files: ') + e)
  }
}

async function loadOperations() {
  if (!apiKey.value) return

  try {
    const data = await getDataPortabilityOperations(50, adminConfig())
    operations.value = Array.isArray(data) ? data : []
  } catch (e) {
    setError(tr('admin_data_portability_error_loading_operations', 'Error loading operations: ') + e)
  }
}

function toggleTransportTable(name) {
  const idx = transportSelectedTables.value.indexOf(name)
  if (idx >= 0) transportSelectedTables.value.splice(idx, 1)
  else transportSelectedTables.value.push(name)
}

function toggleBackupTable(name) {
  const idx = backupSelectedTables.value.indexOf(name)
  if (idx >= 0) backupSelectedTables.value.splice(idx, 1)
  else backupSelectedTables.value.push(name)
}

function toggleRestoreTable(name) {
  const idx = restoreSelectedTables.value.indexOf(name)
  if (idx >= 0) restoreSelectedTables.value.splice(idx, 1)
  else restoreSelectedTables.value.push(name)
}

function useFileForRestore(filePath) {
  selectedRestoreSourcePath.value = filePath || ''
  selectedTab.value = 'restore'
}

function onUploadFileChange(event) {
  selectedUploadFile.value = event?.target?.files?.[0] || null
}

async function uploadFile() {
  if (!selectedUploadFile.value) {
    setError(tr('admin_data_portability_select_file_first', 'Please select a file first.'))
    return
  }

  loading.value = true
  try {
    const result = await uploadDataPortabilityFile(selectedUploadFile.value, adminConfig())
    const uploadedPath = result?.path || ''

    setMessage(
      uploadedPath
        ? tr('admin_data_portability_file_uploaded_with_path', 'File uploaded successfully. Path: ') + uploadedPath
        : tr('admin_data_portability_file_uploaded', 'File uploaded successfully.')
    )

    if (uploadedPath) {
      selectedRestoreSourcePath.value = uploadedPath
    }

    selectedUploadFile.value = null
    await loadBackupFiles()
  } catch (e) {
    setError(tr('admin_data_portability_file_upload_failed', 'File upload failed: ') + e)
  } finally {
    loading.value = false
  }
}

async function downloadFile(row) {
  if (!row?.filename) return

  loading.value = true
  try {
    await downloadDataPortabilityFile(row.filename, adminConfig())
    setMessage(tr('admin_data_portability_file_download_started', 'Download started.'))
  } catch (e) {
    setError(tr('admin_data_portability_file_download_failed', 'File download failed: ') + e)
  } finally {
    loading.value = false
  }
}

async function runBackup() {
  loading.value = true
  try {
    const payload = {}
    if (backupSelectedTables.value.length > 0) {
      payload.selected_tables = backupSelectedTables.value
    }

    const result = await createDataPortabilityBackupOperation(payload, adminConfig())
    const operationId = result?.operation_id

    if (!operationId) {
      throw new Error(tr('admin_data_portability_transport_missing_operation_id', 'No operation ID returned.'))
    }

    setMessage(tr('admin_data_portability_backup_queued', 'Backup operation queued.'))
    startPolling(operationId)
  } catch (e) {
    setError(tr('admin_data_portability_backup_failed', 'Backup execution failed: ') + e)
  } finally {
    loading.value = false
  }
}

async function runRestore() {
  if (!selectedRestoreSourcePath.value.trim()) {
    setError(tr('admin_data_portability_restore_source_required', 'Please enter a restore source file path.'))
    return
  }

  loading.value = true
  try {
    const payload = {
      source_file_path: selectedRestoreSourcePath.value.trim(),
      restore_mode: selectedRestoreMode.value,
    }

    if (restoreSelectedTables.value.length > 0) {
      payload.selected_tables = restoreSelectedTables.value
    }

    const result = await createDataPortabilityRestoreOperation(payload, adminConfig())
    const operationId = result?.operation_id

    if (!operationId) {
      throw new Error(tr('admin_data_portability_transport_missing_operation_id', 'No operation ID returned.'))
    }

    setMessage(tr('admin_data_portability_restore_queued', 'Restore operation queued.'))
    startPolling(operationId)
  } catch (e) {
    setError(tr('admin_data_portability_restore_failed', 'Restore execution failed: ') + e)
  } finally {
    loading.value = false
  }
}

function stopPolling() {
  if (pollingTimer) {
    clearInterval(pollingTimer)
    pollingTimer = null
  }
  operationPolling.value = false
}

function maybeStopPollingByStatus(status) {
  const terminal = ['completed', 'failed']
  if (terminal.includes(String(status || '').toLowerCase())) {
    stopPolling()
  }
}

async function pollOperation(operationId) {
  try {
    const op = await getDataPortabilityOperation(operationId, adminConfig())
    currentOperation.value = op
    await loadOperations()
    maybeStopPollingByStatus(op?.status)
  } catch (e) {
    setError(tr('admin_data_portability_operation_poll_failed', 'Polling operation failed: ') + e)
    stopPolling()
  }
}

function startPolling(operationId) {
  stopPolling()
  operationPolling.value = true

  pollOperation(operationId)
  pollingTimer = setInterval(() => {
    pollOperation(operationId)
  }, 3000)
}

function formatJson(value) {
  try {
    return JSON.stringify(value, null, 2)
  } catch (_) {
    return String(value)
  }
}

async function openOperationDetails(row) {
  detailLoading.value = true
  showOperationDetails.value = true
  operationDetails.value = null

  try {
    const op = await getDataPortabilityOperation(row.id, adminConfig())
    operationDetails.value = op
  } catch (e) {
    setError(tr('admin_data_portability_operation_detail_failed', 'Failed loading operation details: ') + e)
    showOperationDetails.value = false
  } finally {
    detailLoading.value = false
  }
}

async function runTransport() {
  if (!selectedTransportProfileId.value) {
    setError(tr('admin_data_portability_transport_select_profile', 'Please select a transport profile.'))
    return
  }

  if (transportSelectedTables.value.length === 0) {
    setError(tr('admin_data_portability_transport_select_tables', 'Please select at least one table.'))
    return
  }

  loading.value = true
  try {
    const preflight = await preflightDataPortabilityTransport(transportSelectedTables.value, adminConfig())
    if (Array.isArray(preflight?.missing_tables) && preflight.missing_tables.length > 0) {
      preflightResult.value = preflight
      setError(tr('admin_data_portability_preflight_missing', 'Destination is missing tables.'))
      return
    }

    const result = await createDataPortabilityTransportOperation({
      transport_profile_id: Number(selectedTransportProfileId.value),
      selected_tables: transportSelectedTables.value,
      restore_mode: selectedRestoreMode.value,
    }, adminConfig())

    const operationId = result?.operation_id
    if (!operationId) {
      throw new Error(tr('admin_data_portability_transport_missing_operation_id', 'No operation ID returned.'))
    }

    setMessage(tr('admin_data_portability_transport_queued', 'Transport operation queued.'))
    startPolling(operationId)
  } catch (e) {
    setError(tr('admin_data_portability_transport_failed', 'Transport execution failed: ') + e)
  } finally {
    loading.value = false
  }
}

function openCreateProfile() {
  editingProfile.value = null
  profileForm.name = ''
  profileForm.target_base_url = ''
  profileForm.target_api_token = ''
  profileForm.timeout_seconds = 120
  profileForm.is_active = true
  showProfileForm.value = true
}

function openEditProfile(profile) {
  editingProfile.value = profile
  profileForm.name = profile.name || ''
  profileForm.target_base_url = profile.target_base_url || ''
  profileForm.target_api_token = ''
  profileForm.timeout_seconds = profile.timeout_seconds || 120
  profileForm.is_active = !!profile.is_active
  showProfileForm.value = true
}

async function saveProfile() {
  if (!profileForm.name.trim() || !profileForm.target_base_url.trim()) {
    setError(tr('admin_data_portability_profile_required', 'Name and target URL are required.'))
    return
  }

  loading.value = true
  try {
    if (editingProfile.value) {
      const payload = {
        name: profileForm.name.trim(),
        target_base_url: profileForm.target_base_url.trim(),
        timeout_seconds: Number(profileForm.timeout_seconds) || 120,
        is_active: !!profileForm.is_active,
      }

      if (profileForm.target_api_token && profileForm.target_api_token.trim()) {
        payload.target_api_token = profileForm.target_api_token.trim()
      }

      await updateDataPortabilityTransportProfile(editingProfile.value.id, payload, adminConfig())
      setMessage(tr('admin_data_portability_profile_updated', 'Transport profile updated successfully.'))
    } else {
      await createDataPortabilityTransportProfile({
        name: profileForm.name.trim(),
        target_base_url: profileForm.target_base_url.trim(),
        target_api_token: profileForm.target_api_token.trim(),
        timeout_seconds: Number(profileForm.timeout_seconds) || 120,
        is_active: !!profileForm.is_active,
      }, adminConfig())
      setMessage(tr('admin_data_portability_profile_created', 'Transport profile created successfully.'))
    }

    showProfileForm.value = false
    await loadProfiles()
  } catch (e) {
    setError(tr('admin_data_portability_profile_save_failed', 'Saving transport profile failed: ') + e)
  } finally {
    loading.value = false
  }
}

async function removeProfile(profile) {
  if (!confirm(tr('admin_data_portability_profile_delete_confirm', 'Delete this transport profile?'))) {
    return
  }

  loading.value = true
  try {
    await deleteDataPortabilityTransportProfile(profile.id, adminConfig())
    setMessage(tr('admin_data_portability_profile_deleted', 'Transport profile deleted.'))
    await loadProfiles()
  } catch (e) {
    setError(tr('admin_data_portability_profile_delete_failed', 'Deleting transport profile failed: ') + e)
  } finally {
    loading.value = false
  }
}

async function runPreflight() {
  if (selectedTables.value.length === 0) {
    setError(tr('admin_data_portability_preflight_select_tables', 'Please select at least one table.'))
    return
  }

  loading.value = true
  try {
    const data = await preflightDataPortabilityTransport(selectedTables.value, adminConfig())
    preflightResult.value = data

    if (Array.isArray(data?.missing_tables) && data.missing_tables.length > 0) {
      setError(tr('admin_data_portability_preflight_missing', 'Destination is missing tables.'))
    } else {
      setMessage(tr('admin_data_portability_preflight_ok', 'Preflight successful. All selected tables are available on destination.'))
    }
  } catch (e) {
    setError(tr('admin_data_portability_preflight_failed', 'Preflight failed: ') + e)
  } finally {
    loading.value = false
  }
}

function toggleTable(name) {
  const idx = selectedTables.value.indexOf(name)
  if (idx >= 0) selectedTables.value.splice(idx, 1)
  else selectedTables.value.push(name)
}

onMounted(async () => {
  await Promise.all([loadProfiles(), loadTables(), loadBackupFiles(), loadOperations()])
})

onUnmounted(() => {
  stopPolling()
})
</script>

<template>
  <div class="stack">
    <div class="top-bar">
      <h2>{{ tr('admin_data_portability_title', 'Data Portability') }}</h2>
      <div class="top-actions">
        <IconButton icon="refresh" :label="tr('admin_data_portability_reload', 'Reload')" variant="ghost" @click="loadProfiles(); loadTables(); loadBackupFiles(); loadOperations()" :disabled="loading" />
        <IconButton v-if="selectedTab === 'profiles'" icon="plus" :label="tr('admin_data_portability_add_profile', 'Add Profile')" variant="success" @click="openCreateProfile" :disabled="loading" />
      </div>
    </div>

    <div class="admin-tabs">
      <button v-for="tab in tabs" :key="tab.id" :class="['admin-tab-button', { active: selectedTab === tab.id }]" @click="selectedTab = tab.id">
        {{ tab.label }}
      </button>
    </div>

    <div v-if="message" class="message">{{ message }}</div>
    <div v-if="error" class="error">{{ error }}</div>

    <section v-if="selectedTab === 'profiles'" class="group">
      <AdminDataTable
        :columns="profileColumns"
        :rows="profiles"
        :loading="loading"
        row-key="id"
        :persist-key="'admin-data-portability-profiles'"
      >
        <template #row-actions="{ row }">
          <div class="row-actions">
            <IconButton icon="pencil" variant="ghost" size="sm" :label="tr('admin_data_portability_edit_profile', 'Edit profile')" @click="openEditProfile(row)" />
            <IconButton icon="trash" variant="danger" size="sm" :label="tr('admin_data_portability_delete_profile', 'Delete profile')" @click="removeProfile(row)" />
          </div>
        </template>
      </AdminDataTable>
    </section>

    <section v-if="selectedTab === 'preflight'" class="group">
      <h3>{{ tr('admin_data_portability_preflight_title', 'Destination Preflight') }}</h3>
      <p class="hint">{{ tr('admin_data_portability_preflight_hint', 'Select tables and run preflight on this instance to verify table availability for transport.') }}</p>

      <div class="table-selector">
        <label v-for="table in tables" :key="table" class="table-option">
          <input type="checkbox" :checked="selectedTables.includes(table)" @change="toggleTable(table)" />
          <span>{{ table }}</span>
        </label>
      </div>

      <div class="controls">
        <IconButton icon="check" :label="tr('admin_data_portability_run_preflight', 'Run preflight')" @click="runPreflight" :disabled="loading" />
      </div>

      <AdminDataTable
        v-if="preflightResult"
        :columns="preflightColumns"
        :rows="preflightRows"
        :loading="loading"
        row-key="table"
        :persist-key="'admin-data-portability-preflight'"
      />
    </section>

    <section v-if="selectedTab === 'backup'" class="group">
      <h3>{{ tr('admin_data_portability_backup_title', 'Create Backup') }}</h3>
      <p class="hint">{{ tr('admin_data_portability_backup_hint', 'Choose optional tables and start a queued backup operation. If no table is selected, all eligible tables are backed up.') }}</p>

      <div class="table-selector">
        <label v-for="table in tables" :key="`backup-${table}`" class="table-option">
          <input type="checkbox" :checked="backupSelectedTables.includes(table)" @change="toggleBackupTable(table)" />
          <span>{{ table }}</span>
        </label>
      </div>

      <div class="controls">
        <IconButton icon="save" :label="tr('admin_data_portability_run_backup', 'Run backup')" @click="runBackup" :disabled="loading" />
      </div>

      <AdminDataTable
        :columns="operationColumns"
        :rows="visibleOperations"
        :loading="loading"
        row-key="id"
        :persist-key="'admin-data-portability-backup-operations'"
      >
        <template #cell-status="{ value }">
          <span :class="statusBadgeClass(value)">{{ statusLabel(value) }}</span>
        </template>
        <template #row-actions="{ row }">
          <div class="row-actions">
            <IconButton icon="eye" variant="ghost" size="sm" :label="tr('admin_data_portability_operation_details', 'Details')" @click="openOperationDetails(row)" />
          </div>
        </template>
      </AdminDataTable>
    </section>

    <section v-if="selectedTab === 'files'" class="group">
      <h3>{{ tr('admin_data_portability_files_title', 'Backup Files') }}</h3>
      <p class="hint">{{ tr('admin_data_portability_files_hint', 'Upload restore files and download generated backups.') }}</p>

      <div class="upload-row">
        <input type="file" accept=".json,application/json,text/plain" @change="onUploadFileChange" />
        <IconButton icon="upload" :label="tr('admin_data_portability_upload_file', 'Upload file')" @click="uploadFile" :disabled="loading || !selectedUploadFile" />
      </div>

      <AdminDataTable
        :columns="fileColumns"
        :rows="fileRows"
        :loading="loading"
        row-key="path"
        :persist-key="'admin-data-portability-files'"
      >
        <template #row-actions="{ row }">
          <div class="row-actions">
            <IconButton icon="download" variant="ghost" size="sm" :label="tr('admin_data_portability_download_file', 'Download file')" @click="downloadFile(row)" />
            <IconButton icon="arrow-right" variant="ghost" size="sm" :label="tr('admin_data_portability_use_for_restore', 'Use for restore')" @click="useFileForRestore(row.path)" />
          </div>
        </template>
      </AdminDataTable>
    </section>

    <section v-if="selectedTab === 'restore'" class="group">
      <h3>{{ tr('admin_data_portability_restore_title', 'Run Restore') }}</h3>
      <p class="hint">{{ tr('admin_data_portability_restore_hint', 'Select a source file path, optional table selection, and restore mode. Restore runs in the queue.') }}</p>

      <div class="restore-grid">
        <label class="field">
          <span>{{ tr('admin_data_portability_restore_source_path', 'Source File Path') }}</span>
          <input v-model="selectedRestoreSourcePath" type="text" :placeholder="tr('admin_data_portability_restore_source_placeholder', 'data-portability/backups/backup-...json')" />
        </label>

        <label class="field">
          <span>{{ tr('admin_data_portability_restore_mode', 'Restore Mode') }}</span>
          <select v-model="selectedRestoreMode">
            <option value="truncate_insert">{{ tr('admin_data_portability_restore_truncate_insert', 'truncate_insert') }}</option>
            <option value="upsert">{{ tr('admin_data_portability_restore_upsert', 'upsert') }}</option>
          </select>
        </label>
      </div>

      <label class="field">
        <span>{{ tr('admin_data_portability_restore_quick_select', 'Quick Select from Backups') }}</span>
        <select @change="selectedRestoreSourcePath = $event.target.value">
          <option value="">{{ tr('admin_data_portability_restore_quick_select_placeholder', 'Select backup file...') }}</option>
          <option v-for="file in backupFiles" :key="`restore-file-${file.path}`" :value="file.path">{{ file.path }}</option>
        </select>
      </label>

      <div class="table-selector">
        <label v-for="table in tables" :key="`restore-${table}`" class="table-option">
          <input type="checkbox" :checked="restoreSelectedTables.includes(table)" @change="toggleRestoreTable(table)" />
          <span>{{ table }}</span>
        </label>
      </div>

      <div class="controls">
        <IconButton icon="upload" :label="tr('admin_data_portability_run_restore', 'Run restore')" @click="runRestore" :disabled="loading" />
      </div>

      <AdminDataTable
        :columns="operationColumns"
        :rows="visibleOperations"
        :loading="loading"
        row-key="id"
        :persist-key="'admin-data-portability-restore-operations'"
      >
        <template #cell-status="{ value }">
          <span :class="statusBadgeClass(value)">{{ statusLabel(value) }}</span>
        </template>
        <template #row-actions="{ row }">
          <div class="row-actions">
            <IconButton icon="eye" variant="ghost" size="sm" :label="tr('admin_data_portability_operation_details', 'Details')" @click="openOperationDetails(row)" />
          </div>
        </template>
      </AdminDataTable>
    </section>

    <section v-if="selectedTab === 'transport'" class="group">
      <h3>{{ tr('admin_data_portability_transport_title', 'Execute Transport') }}</h3>
      <p class="hint">{{ tr('admin_data_portability_transport_hint', 'Choose profile and tables, then run transport. Operation state is polled live.') }}</p>

      <div class="transport-grid">
        <label class="field">
          <span>{{ tr('admin_data_portability_transport_profile', 'Transport Profile') }}</span>
          <select v-model="selectedTransportProfileId">
            <option :value="null">{{ tr('admin_data_portability_transport_profile_placeholder', 'Select profile...') }}</option>
            <option v-for="profile in activeProfiles" :key="profile.id" :value="profile.id">
              {{ profile.name }} - {{ profile.target_base_url }}
            </option>
          </select>
        </label>

        <label class="field">
          <span>{{ tr('admin_data_portability_transport_restore_mode', 'Restore Mode') }}</span>
          <select v-model="selectedRestoreMode">
            <option value="truncate_insert">{{ tr('admin_data_portability_restore_truncate_insert', 'truncate_insert') }}</option>
            <option value="upsert">{{ tr('admin_data_portability_restore_upsert', 'upsert') }}</option>
          </select>
        </label>
      </div>

      <div class="table-selector">
        <label v-for="table in tables" :key="`transport-${table}`" class="table-option">
          <input type="checkbox" :checked="transportSelectedTables.includes(table)" @change="toggleTransportTable(table)" />
          <span>{{ table }}</span>
        </label>
      </div>

      <div class="controls">
        <IconButton icon="send" :label="tr('admin_data_portability_run_transport', 'Run transport')" @click="runTransport" :disabled="loading" />
        <IconButton icon="refresh" variant="ghost" :label="tr('admin_data_portability_reload_operations', 'Reload operations')" @click="loadOperations" :disabled="loading" />
      </div>

      <div v-if="currentOperation" class="operation-panel">
        <strong>{{ tr('admin_data_portability_current_operation', 'Current operation') }} #{{ currentOperation.id }}</strong>
        <span>{{ tr('admin_data_portability_operation_status', 'Status') }}: {{ currentOperation.status }}</span>
        <span v-if="operationPolling" class="polling">{{ tr('admin_data_portability_polling', 'Polling...') }}</span>
      </div>

      <AdminDataTable
        :columns="operationColumns"
        :rows="visibleOperations"
        :loading="loading"
        row-key="id"
        :persist-key="'admin-data-portability-transport-operations'"
      >
        <template #cell-status="{ value }">
          <span :class="statusBadgeClass(value)">{{ statusLabel(value) }}</span>
        </template>
        <template #row-actions="{ row }">
          <div class="row-actions">
            <IconButton icon="eye" variant="ghost" size="sm" :label="tr('admin_data_portability_operation_details', 'Details')" @click="openOperationDetails(row)" />
          </div>
        </template>
      </AdminDataTable>
    </section>

    <div v-if="showOperationDetails" class="modal-backdrop" @click.self="showOperationDetails = false">
      <div class="modal detail-modal">
        <h3>{{ tr('admin_data_portability_operation_details_title', 'Operation Details') }}</h3>

        <div v-if="detailLoading" class="hint">{{ tr('admin_data_portability_loading_operation_details', 'Loading operation details...') }}</div>

        <template v-else-if="operationDetails">
          <div class="detail-grid">
            <div class="detail-item"><strong>ID:</strong> {{ operationDetails.id }}</div>
            <div class="detail-item"><strong>{{ tr('admin_data_portability_operation_type', 'Type') }}:</strong> {{ operationDetails.type }}</div>
            <div class="detail-item status-line">
              <strong>{{ tr('admin_data_portability_operation_status', 'Status') }}:</strong>
              <span :class="statusBadgeClass(operationDetails.status)">{{ statusLabel(operationDetails.status) }}</span>
            </div>
            <div class="detail-item"><strong>{{ tr('admin_data_portability_operation_restore_mode', 'Restore Mode') }}:</strong> {{ operationDetails.restore_mode || '-' }}</div>
            <div class="detail-item"><strong>{{ tr('admin_data_portability_operation_created_at', 'Created') }}:</strong> {{ operationDetails.created_at || '-' }}</div>
            <div class="detail-item"><strong>{{ tr('admin_data_portability_operation_finished_at', 'Finished') }}:</strong> {{ operationDetails.finished_at || '-' }}</div>
          </div>

          <div v-if="operationDetails.error_message" class="error">
            <strong>{{ tr('admin_data_portability_operation_error', 'Error') }}:</strong>
            <div>{{ operationDetails.error_message }}</div>
          </div>

          <div v-if="detailTransportResult" class="detail-block">
            <h4>{{ tr('admin_data_portability_transport_result', 'Transport Result') }}</h4>
            <pre>{{ formatJson(detailTransportResult) }}</pre>
          </div>

          <div v-if="detailRestoreResult" class="detail-block">
            <h4>{{ tr('admin_data_portability_restore_result', 'Restore Result') }}</h4>
            <pre>{{ formatJson(detailRestoreResult) }}</pre>
          </div>

          <details class="detail-block">
            <summary>{{ tr('admin_data_portability_raw_options', 'Raw Options') }}</summary>
            <pre>{{ formatJson(operationDetails.options || {}) }}</pre>
          </details>
        </template>

        <div class="modal-actions">
          <IconButton icon="close" variant="ghost" :label="tr('admin_data_portability_close', 'Close')" @click="showOperationDetails = false" />
        </div>
      </div>
    </div>

    <div v-if="showProfileForm" class="modal-backdrop" @click.self="showProfileForm = false">
      <div class="modal">
        <h3>{{ editingProfile ? tr('admin_data_portability_edit_profile', 'Edit Transport Profile') : tr('admin_data_portability_add_profile', 'Add Transport Profile') }}</h3>

        <label class="field">
          <span>{{ tr('admin_data_portability_profile_name', 'Profile Name') }}</span>
          <input v-model="profileForm.name" type="text" />
        </label>

        <label class="field">
          <span>{{ tr('admin_data_portability_profile_target_url', 'Target Base URL') }}</span>
          <input v-model="profileForm.target_base_url" type="url" />
        </label>

        <label class="field">
          <span>{{ tr('admin_data_portability_profile_token', 'Target API Token') }}</span>
          <SecretField v-model="profileForm.target_api_token" />
          <small v-if="editingProfile" class="hint">{{ tr('admin_data_portability_profile_token_hint', 'Leave empty to keep the current token.') }}</small>
        </label>

        <label class="field">
          <span>{{ tr('admin_data_portability_profile_timeout', 'Timeout (seconds)') }}</span>
          <input v-model.number="profileForm.timeout_seconds" type="number" min="10" max="600" />
        </label>

        <label class="field checkbox">
          <input v-model="profileForm.is_active" type="checkbox" />
          <span>{{ tr('admin_data_portability_profile_active', 'Active') }}</span>
        </label>

        <div class="modal-actions">
          <IconButton icon="close" variant="ghost" :label="tr('admin_data_portability_cancel', 'Cancel')" @click="showProfileForm = false" />
          <IconButton icon="save" :label="tr('admin_data_portability_save', 'Save')" @click="saveProfile" :disabled="loading" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 0.85rem; width: 100%; }
.top-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
.top-bar h2 { margin: 0; color: var(--text); }
.top-actions { display: flex; gap: 0.5rem; }
.group { border: 1px solid var(--border-strong); border-radius: 10px; padding: 1rem; background: var(--app-card-bg, var(--surface)); box-shadow: 0 4px 14px var(--shadow); display: flex; flex-direction: column; gap: 0.75rem; color: var(--text); }
.group h3 { margin: 0; color: var(--text); }
.hint { color: var(--text-muted); margin: 0; }
.controls { display: flex; gap: 0.5rem; }
.transport-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.75rem; }
.restore-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.75rem; }
.upload-row { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.upload-row input[type='file'] { color: var(--text); }
.row-actions { display: flex; gap: 0.35rem; justify-content: flex-end; }
.table-selector { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.4rem 0.8rem; max-height: 220px; overflow: auto; border: 1px solid var(--border); border-radius: 8px; padding: 0.6rem; background: var(--surface); }
.table-option { display: flex; align-items: center; gap: 0.45rem; color: var(--text); font-size: 0.92rem; }
.operation-panel { display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: center; background: var(--surface-muted); border: 1px solid var(--border); border-radius: 8px; padding: 0.6rem; color: var(--text); }
.polling { color: var(--primary); font-weight: 600; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 40; }
.modal { width: min(620px, 100%); border-radius: 12px; border: 1px solid var(--border-strong); background: var(--app-card-bg, var(--surface)); color: var(--text); box-shadow: 0 20px 50px var(--shadow); padding: 1rem; display: flex; flex-direction: column; gap: 0.7rem; }
.detail-modal { width: min(860px, 100%); }
.field { display: flex; flex-direction: column; gap: 0.35rem; color: var(--text); font-weight: 600; }
.field input[type='text'],
.field input[type='url'],
.field input[type='number'],
.field select { width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
.field.checkbox { flex-direction: row; align-items: center; gap: 0.5rem; font-weight: 500; }
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.4rem 0.8rem; }
.detail-item { color: var(--text); font-size: 0.95rem; }
.status-line { display: flex; align-items: center; gap: 0.45rem; }
.detail-block { border: 1px solid var(--border); background: var(--surface-muted); border-radius: 8px; padding: 0.65rem; color: var(--text); }
.detail-block h4 { margin: 0 0 0.45rem 0; font-size: 0.95rem; }
.detail-block pre { margin: 0; white-space: pre-wrap; word-break: break-word; max-height: 220px; overflow: auto; font-size: 0.85rem; }

.status-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  padding: 0.15rem 0.55rem;
  font-size: 0.78rem;
  font-weight: 700;
  border: 1px solid transparent;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.status-badge.queued {
  background: var(--surface-strong);
  border-color: var(--border);
  color: var(--text-muted);
}

.status-badge.running {
  background: color-mix(in srgb, var(--primary) 20%, transparent);
  border-color: color-mix(in srgb, var(--primary) 45%, transparent);
  color: var(--text);
}

.status-badge.completed {
  background: var(--success-bg);
  border-color: var(--success-border);
  color: var(--success-text);
}

.status-badge.failed {
  background: var(--error-bg);
  border-color: var(--error-border);
  color: var(--error-text);
}

@media (max-width: 640px) {
  .table-selector { grid-template-columns: 1fr; }
}
</style>
