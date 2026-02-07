<script setup>
import { computed, onMounted, ref, watch, onUnmounted } from 'vue'
import IconButton from './IconButton.vue'

const props = defineProps({
  columns: { type: Array, default: () => [] },
  rows: { type: Array, default: () => [] },
  rowKey: { type: String, default: 'id' },
  loading: { type: Boolean, default: false },
  selectable: { type: Boolean, default: false },
  modelValue: { type: Array, default: () => [] },
  pageSize: { type: Number, default: 20 },
  enableSearch: { type: Boolean, default: true },
  clientSort: { type: Boolean, default: true },
  initialHiddenColumns: { type: Array, default: () => [] },
  persistKey: { type: String, default: null },
  emptyText: { type: String, default: 'Keine Einträge vorhanden.' },
  rowDraggable: { type: Boolean, default: false },
  autoRefreshDefaultEnabled: { type: Boolean, default: false },
  autoRefreshDefaultIntervalMs: { type: Number, default: 10000 },
  autoRefreshIntervals: { type: Array, default: () => [5000, 10000, 30000, 60000] },
})

const emit = defineEmits(['update:modelValue', 'sort-change', 'refresh', 'reorder', 'auto-refresh'])

const search = ref('')
const sortKey = ref(null)
const sortDir = ref('asc')
const page = ref(1)
const hiddenColumns = ref(new Set(props.initialHiddenColumns))
const selected = ref([...props.modelValue])
const dragIndex = ref(null)
const autoRefreshEnabled = ref(!!props.autoRefreshDefaultEnabled)
const autoRefreshInterval = ref(props.autoRefreshDefaultIntervalMs)
const autoRefreshLock = ref(false)
let autoRefreshTimer = null

onMounted(() => {
  if (props.persistKey) {
    const saved = localStorage.getItem(`${props.persistKey}:cols`)
    if (saved) {
      try { hiddenColumns.value = new Set(JSON.parse(saved)) } catch (_) {}
    }
    const savedSearch = localStorage.getItem(`${props.persistKey}:search`)
    if (savedSearch) search.value = savedSearch
    const savedSort = localStorage.getItem(`${props.persistKey}:sort`)
    if (savedSort) {
      try {
        const parsed = JSON.parse(savedSort)
        sortKey.value = parsed.key ?? null
        sortDir.value = parsed.dir === 'desc' ? 'desc' : 'asc'
      } catch (_) {}
    }
    const savedAuto = localStorage.getItem(`${props.persistKey}:autorefresh`)
    if (savedAuto) {
      try {
        const parsed = JSON.parse(savedAuto)
        autoRefreshEnabled.value = !!parsed.enabled
        if (parsed.interval) autoRefreshInterval.value = parsed.interval
      } catch (_) {}
    }
  }
  startAutoRefresh()
})

onUnmounted(() => {
  stopAutoRefresh()
})

watch(() => props.modelValue, (val) => {
  selected.value = [...val]
})

watch(search, (val) => {
  if (props.persistKey) localStorage.setItem(`${props.persistKey}:search`, val)
  page.value = 1
})

watch(hiddenColumns, (val) => {
  if (props.persistKey) localStorage.setItem(`${props.persistKey}:cols`, JSON.stringify([...val]))
}, { deep: true })

watch([autoRefreshEnabled, autoRefreshInterval], () => {
  if (props.persistKey) {
    localStorage.setItem(`${props.persistKey}:autorefresh`, JSON.stringify({ enabled: autoRefreshEnabled.value, interval: autoRefreshInterval.value }))
  }
  startAutoRefresh()
})

function startAutoRefresh() {
  stopAutoRefresh()
  if (!autoRefreshEnabled.value) return
  autoRefreshTimer = setInterval(() => {
    triggerRefresh(true)
  }, autoRefreshInterval.value)
}

function stopAutoRefresh() {
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer)
    autoRefreshTimer = null
  }
}

function triggerRefresh(isAuto = false) {
  if (props.loading) return
  if (isAuto && autoRefreshLock.value) return
  autoRefreshLock.value = true
  if (isAuto) emit('auto-refresh', { auto: true })
  else emit('refresh', { auto: false })
  setTimeout(() => { autoRefreshLock.value = false }, 500)
}

function toggleColumn(key) {
  const next = new Set(hiddenColumns.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  hiddenColumns.value = next
}

function isVisible(key) {
  return !hiddenColumns.value.has(key)
}

function toggleSort(key) {
  if (props.rowDraggable) return
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
  if (props.persistKey) localStorage.setItem(`${props.persistKey}:sort`, JSON.stringify({ key: sortKey.value, dir: sortDir.value }))
  emit('sort-change', { key: sortKey.value, dir: sortDir.value })
}

const displayColumns = computed(() => props.columns.filter(c => isVisible(c.key)))

const filteredRows = computed(() => {
  const rows = Array.isArray(props.rows) ? props.rows : []
  if (!props.enableSearch || !search.value.trim()) return rows
  const term = search.value.toLowerCase()
  return rows.filter((row) => {
    return props.columns.some((col) => {
      const val = row[col.key]
      if (val == null) return false
      return String(val).toLowerCase().includes(term)
    })
  })
})

const sortedRows = computed(() => {
  if (props.rowDraggable) return filteredRows.value
  if (!props.clientSort || !sortKey.value) return filteredRows.value
  const list = [...filteredRows.value]
  list.sort((a, b) => {
    const va = a[sortKey.value]
    const vb = b[sortKey.value]
    if (va === vb) return 0
    if (va == null) return -1
    if (vb == null) return 1
    if (typeof va === 'number' && typeof vb === 'number') return sortDir.value === 'asc' ? va - vb : vb - va
    return sortDir.value === 'asc' ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va))
  })
  return list
})

const pageCount = computed(() => Math.max(1, Math.ceil(sortedRows.value.length / props.pageSize)))

watch(sortedRows, () => {
  if (page.value > pageCount.value) page.value = pageCount.value
})

const pagedRows = computed(() => {
  const start = (page.value - 1) * props.pageSize
  return sortedRows.value.slice(start, start + props.pageSize)
})

function allVisibleSelected() {
  const keys = pagedRows.value.map(r => r[props.rowKey])
  return keys.length > 0 && keys.every(id => selected.value.includes(id))
}

function toggleSelectAll() {
  const keys = pagedRows.value.map(r => r[props.rowKey])
  if (allVisibleSelected()) {
    selected.value = selected.value.filter(id => !keys.includes(id))
  } else {
    selected.value = Array.from(new Set([...selected.value, ...keys]))
  }
  emit('update:modelValue', selected.value)
}

function toggleRow(id) {
  if (selected.value.includes(id)) {
    selected.value = selected.value.filter(v => v !== id)
  } else {
    selected.value = [...selected.value, id]
  }
  emit('update:modelValue', selected.value)
}

function onDragStart(globalIndex) {
  dragIndex.value = globalIndex
}

function onDrop(globalIndex) {
  if (dragIndex.value === null || dragIndex.value === globalIndex) return
  const items = [...sortedRows.value]
  const [moved] = items.splice(dragIndex.value, 1)
  items.splice(globalIndex, 0, moved)
  dragIndex.value = null
  emit('reorder', items.map(r => r[props.rowKey]))
}
</script>

<template>
  <div class="table-card">
    <div class="table-toolbar">
      <div class="toolbar-left">
        <slot name="title"></slot>
        <slot name="actions"></slot>
      </div>
      <div class="toolbar-right">
        <div v-if="enableSearch" class="search">
          <input v-model="search" placeholder="Suchen..." />
        </div>
        <div class="toolbar-icons">
          <div class="auto-refresh">
            <IconButton
              icon="repeat"
              size="sm"
              variant="ghost"
              :aria-pressed="autoRefreshEnabled"
              :class="{ active: autoRefreshEnabled }"
              label="Auto-Refresh umschalten"
              @click="autoRefreshEnabled = !autoRefreshEnabled"
            />
            <details class="dropdown interval-picker">
              <summary>
                <IconButton as="span" icon="clock" size="sm" variant="ghost" label="Intervall wählen" />
              </summary>
              <div class="dropdown-panel">
                <label>
                  Intervall
                  <select v-model.number="autoRefreshInterval" :disabled="!autoRefreshEnabled">
                    <option v-for="ms in autoRefreshIntervals" :key="ms" :value="ms">{{ Math.round(ms/1000) }}s</option>
                  </select>
                </label>
              </div>
            </details>
          </div>
          <details class="column-toggle" v-if="columns.length">
            <summary>
              <IconButton as="span" icon="columns" size="sm" variant="ghost" label="Spalten ein-/ausblenden" />
            </summary>
            <div class="column-list">
              <label v-for="col in columns" :key="col.key">
                <input type="checkbox" :checked="isVisible(col.key)" @change="toggleColumn(col.key)" /> {{ col.label }}
              </label>
            </div>
          </details>
          <IconButton icon="refresh" size="sm" label="Neu laden" variant="ghost" @click="triggerRefresh(false)" :disabled="loading" />
        </div>
      </div>
    </div>

    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th v-if="rowDraggable" class="drag-col"></th>
            <th v-if="selectable"><input type="checkbox" :checked="allVisibleSelected()" @change="toggleSelectAll" /></th>
            <th v-for="col in displayColumns" :key="col.key" @click="col.sortable !== false ? toggleSort(col.key) : null" :class="{ sortable: col.sortable !== false && !rowDraggable }">
              <span>{{ col.label }}</span>
              <span v-if="!rowDraggable && sortKey === col.key" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
            </th>
            <th v-if="$slots['row-actions']"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td :colspan="displayColumns.length + (selectable ? 1 : 0) + ($slots['row-actions'] ? 1 : 0) + (rowDraggable ? 1 : 0)">Lade...</td>
          </tr>
          <tr v-else-if="!pagedRows.length">
            <td :colspan="displayColumns.length + (selectable ? 1 : 0) + ($slots['row-actions'] ? 1 : 0) + (rowDraggable ? 1 : 0)">{{ emptyText }}</td>
          </tr>
          <tr
            v-else
            v-for="(row, idx) in pagedRows"
            :key="row[rowKey]"
            :draggable="rowDraggable"
            @dragstart.prevent="onDragStart((page - 1) * pageSize + idx)"
            @dragover.prevent
            @drop.prevent="onDrop((page - 1) * pageSize + idx)"
            :class="{ draggable: rowDraggable }"
          >
            <td v-if="rowDraggable" class="drag-col" title="Zum Verschieben ziehen">⋮⋮</td>
            <td v-if="selectable"><input type="checkbox" :checked="selected.includes(row[rowKey])" @change="toggleRow(row[rowKey])" /></td>
            <td v-for="col in displayColumns" :key="col.key">
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                {{ row[col.key] ?? '–' }}
              </slot>
            </td>
            <td v-if="$slots['row-actions']" class="actions" @click.stop>
              <slot name="row-actions" :row="row" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="table-footer" v-if="pageCount > 1">
      <div>Seite {{ page }} / {{ pageCount }}</div>
      <div class="pager">
        <IconButton icon="chevronLeft" size="sm" variant="ghost" label="Zurück" @click="page = Math.max(1, page - 1)" :disabled="page === 1" />
        <IconButton icon="chevronRight" size="sm" variant="ghost" label="Weiter" @click="page = Math.min(pageCount, page + 1)" :disabled="page === pageCount" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.table-card { border: 1px solid var(--border-strong); border-radius: 8px; background: var(--app-card-bg, var(--card)); display: flex; flex-direction: column; gap: 0.5rem; padding: 0.75rem; box-shadow: 0 6px 18px var(--shadow); color: var(--text); }
.table-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.toolbar-left { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.toolbar-right { display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; justify-content: flex-end; flex: 1 1 auto; min-width: 0; }
.toolbar-icons { display: flex; align-items: center; gap: 0.35rem; flex-wrap: nowrap; flex-shrink: 0; }
.table-wrapper { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; color: var(--text); }
th, td { border-bottom: 1px solid var(--border-strong); padding: 0.5rem; text-align: left; vertical-align: top; color: var(--text); }
th.sortable { cursor: pointer; }
.sort-indicator { margin-left: 0.25rem; font-size: 0.8em; color: var(--text-muted); }
.search { flex: 1 1 110px; display: none; min-width: 0; }
.search input { padding: 0.4rem 0.5rem; border: 1px solid var(--border); border-radius: 6px; width: 92%; background: var(--surface); color: var(--text); }
@media (min-width: 768px) { .search { display: block; max-width: 260px; } }
.column-toggle summary, .dropdown summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; color: var(--text); }
.column-toggle summary::-webkit-details-marker, .dropdown summary::-webkit-details-marker { display: none; }
.column-toggle { position: relative; }
.column-toggle .column-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.25rem; padding: 0.5rem; border: 1px solid var(--border-strong); border-radius: 8px; background: var(--surface-muted); box-shadow: 0 6px 18px var(--shadow); position: absolute; right: 0; top: 110%; z-index: 5; min-width: 220px; color: var(--text); }
.dropdown { position: relative; }
.dropdown .dropdown-panel { position: absolute; right: 0; top: 110%; z-index: 5; border: 1px solid var(--border-strong); border-radius: 8px; background: var(--surface-muted); padding: 0.5rem; box-shadow: 0 6px 18px var(--shadow); min-width: 180px; color: var(--text); }
.dropdown .dropdown-panel select { width: 100%; padding: 0.35rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text); }
.table-footer { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; color: var(--text); }
.pager { display: flex; gap: 0.35rem; }
.actions { display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: flex-end; align-items: center; }
.drag-col { width: 32px; text-align: center; cursor: grab; color: var(--text-muted); }
tr.draggable:hover { background: var(--surface-muted); }
.auto-refresh { display: flex; align-items: center; gap: 0.2rem; }
.auto-refresh .active { box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
/* allow clicking icon buttons to toggle details */
.column-toggle summary .icon-btn, .dropdown summary .icon-btn { pointer-events: auto; }
</style>
