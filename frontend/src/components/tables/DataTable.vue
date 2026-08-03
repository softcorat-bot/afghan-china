<template>
  <div>
    <q-table
      :title="title"
      :rows="data"
      :columns="processedColumns"
      :row-key="rowKey ? rowKey : 'id'"
      v-model:pagination="paginations"
      :rows-per-page-options="[5, 10, 20, 50, 100]"
      :loading="loading"
      :filter="filter"
      :dense="dense"
      title-class="desktop-only"
      :no-data-label="$t('NoRecordFound')"
      :no-results-label="$t('FilterNoResult')"
      :visible-columns="effectiveVisible"
      @request="onRequest"
      binary-state-sort
      color="negative"
      v-model:fullscreen="isFullscreen"
      class="q-ma-sm my_radio_less three_d_new"
    >
      <template v-slot:no-data>
        <div v-if="!loading" class="text-center w-full full-width q-py-xl">
          <slot name="no-data">
            <q-icon color="primary" name="search" size="6em" />
            <div class="text-subtitle1 q-mt-sm">{{ $t('NoRecordFound') }}</div>
          </slot>
        </div>
      </template>

      <template v-slot:loading>
        <q-inner-loading showing color="primary" />
      </template>

      <template v-slot:header="props">
        <q-tr class="bg-theme-soft" :props="props">
          <q-th
            style="font-family: poppins, sans-serif"
            v-for="col in props.cols"
            :key="col.name"
            :props="props"
            class="dt-head-th"
            @click="head(col.name)"
          >
            {{ $t(col.label) }}
          </q-th>
        </q-tr>
      </template>

      <template v-slot:body-cell-created_at="props">
        <q-td key="created_at" :props="props">
          {{ props.rowIndex + 1 }}
        </q-td>
      </template>

      <template v-slot:body-cell-actions="props">
        <q-td key="actions" :props="props">
          <q-btn
            size="sm"
            color="blue-8"
            dense
            class="q-ml-xs"
            v-show="noEdit ? false : true"
            icon="edit"
            v-if="$can(can_edit)"
            @click="$emit('edit', props.row.id)"
          />
          <q-btn
            size="sm"
            color="teal-7"
            dense
            class="q-ml-xs"
            v-show="noInfo ? false : true"
            :icon="infoIcon ? infoIcon : 'info'"
            @click="onInfo(props.row)"
            v-if="$can(can_show)"
          />
          <q-btn
            size="sm"
            color="deep-orange"
            dense
            class="q-ml-xs"
            v-show="yesPrint ? true : false"
            icon="mdi-printer"
            @click="$emit('print', props.row.id)"
            v-if="$can(can_print)"
          />
          <q-btn
            size="sm"
            color="negative"
            dense
            class="q-ml-xs"
            v-show="noDelete ? false : true"
            icon="delete"
            @click="$emit('del', props.row.id)"
            v-if="$can(can_delete)"
          />
        </q-td>
      </template>

      <template v-slot:top-right>
        <div class="desktop-only">
          <slot name="vissibleCols"></slot>
        </div>

        <!-- Column Visibility -->
        <q-btn flat dense icon="view_column" size="sm" color="grey-7" class="q-ml-xs desktop-only">
          <q-tooltip>{{ $t('ShowHideColumns') }}</q-tooltip>
          <q-menu>
            <q-list dense style="min-width:160px">
              <q-item v-for="col in toggleableColumns" :key="col.name" tag="label" dense>
                <q-item-section side><q-checkbox v-model="localVisible" :val="col.name" dense /></q-item-section>
                <q-item-section>{{ $t(col.label) }}</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-btn>

        <q-btn
          flat
          round
          dense
          :icon="isFullscreen ? 'fullscreen_exit' : 'fullscreen'"
          @click="isFullscreen = !isFullscreen"
          class="q-ml-md desktop-only"
        />
        <q-toggle class="desktop-only" :label="$t('MSizer')" v-model="dense" icon="apps" />
        <q-input
          standout="bg-primary"
          class="q-ml-sm transition duration-500 ease-in-out transform hover:scale-105"
          :loading="loading"
          dense
          debounce="300"
          :model-value="filter"
          @update:model-value="$emit('update:filter', $event)"
          :placeholder="$t('Search')"
        >
          <template v-slot:append>
            <q-icon name="search" />
          </template>
        </q-input>
      </template>

      <template v-for="(_, slotName) in $slots" :key="slotName" v-slot:[slotName]="slotData">
        <slot :name="slotName" v-bind="slotData ?? {}" />
      </template>
    </q-table>

    <!-- Built-in Row Info Modal -->
    <m-modal :showCM="infoDialog" @update:showCM="infoDialog = $event" card_style="width:600px">
      <q-card class="bg-white" v-if="infoRow">
        <n-header icon="info">Record Details</n-header>
        <q-separator />
        <q-card-section class="q-pa-md">
          <!-- Gradient accent bar -->
          <div style="height:4px;border-radius:4px;background:linear-gradient(90deg,#0097a7,#0288d1,#5c6bc0);margin-bottom:16px"></div>
          <div class="row q-col-gutter-md">
            <template v-for="col in infoColumns" :key="col.name">
              <div class="col-12 col-sm-6">
                <div style="border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)">
                  <div style="background:#f0f9ff;padding:4px 12px;font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:#0097a7;font-weight:600;border-bottom:1px solid #e0f7fa">
                    {{ $t(col.label) }}
                  </div>
                  <div style="padding:10px 12px;font-size:14px;font-weight:600;color:var(--on-surface);background:var(--surface-card);min-height:38px;display:flex;align-items:center">
                    {{ getColVal(col, infoRow) }}
                  </div>
                </div>
              </div>
            </template>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-px-md q-pb-md">
          <q-btn unelevated color="blue-grey-7" label="Close" @click="infoDialog = false" />
        </q-card-actions>
      </q-card>
    </m-modal>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { copyToClipboard as qCopy, Notify } from 'quasar'
import { fmtDate, isDateColumn } from '@/utils/date'

const props = defineProps([
  'loading', 'data', 'pagination', 'filter', 'columns', 'rowKey', 'infoIcon', 'noInfoDialog',
  'title', 'noEdit', 'noInfo', 'noDelete', 'can_edit', 'can_show', 'can_delete',
  'can_print', 'visibleColumns', 'yesPrint',
  // Give the info button a destination and it becomes a link to a full page
  // instead of opening the cramped built-in modal. Either a function
  // (row) => path, or a string prefix that gets "/<id>" appended.
  'infoRoute',
  // Overrides the storage key for the remembered column choice. Only needed
  // when two tables share one route.
  'tableKey'
])
const emit = defineEmits(['del', 'edit', 'info', 'print', 'head', 'request', 'update:filter', 'update:pagination'])

const paginations = ref({
  sortBy: 'created_at',
  descending: true,
  page: 1,
  rowsPerPage: 10
})
const isFullscreen = ref(false)

/**
 * The toolbar's choices — which columns show, and compact rows — are the
 * user's, so they survive a reload instead of resetting on every visit.
 * Keyed by route (or an explicit `tableKey` when one route holds two tables).
 */
const route = useRoute()
const router = useRouter()
const prefsKey = computed(() => 'acsc-table:' + (props.tableKey || route?.path || 'default'))

function readPrefs () {
  try {
    const raw = localStorage.getItem(prefsKey.value)
    return raw ? JSON.parse(raw) : null
  } catch { return null }
}
function writePrefs (patch) {
  try {
    localStorage.setItem(prefsKey.value, JSON.stringify({ ...(readPrefs() || {}), ...patch }))
  } catch { /* private mode / quota — the table still works, just forgets */ }
}

const dense = ref(readPrefs()?.dense ?? true)
watch(dense, (v) => writePrefs({ dense: v }))
const infoDialog = ref(false)
const infoRow = ref(null)

const infoColumns = computed(() =>
  (props.columns || []).filter(c => c.name !== 'actions' && c.name !== 'created_at')
)

function onInfo(row) {
  emit('info', row.id, row)

  // A real page beats a modal: it can carry the whole history, be linked to,
  // bookmarked and printed. Pages that provide one get it.
  if (props.infoRoute) {
    const to = typeof props.infoRoute === 'function'
      ? props.infoRoute(row)
      : `${String(props.infoRoute).replace(/\/$/, '')}/${row.id}`
    if (to) { router.push(to); return }
  }

  // Pages with their own detail modal suppress the built-in one.
  if (props.noInfoDialog) return
  infoRow.value = row
  infoDialog.value = true
}

function getColVal(col, row) {
  const raw = typeof col.field === 'function' ? col.field(row) : row[col.field ?? col.name]
  if (typeof col.format === 'function') return col.format(raw) ?? '—'
  return raw ?? '—'
}

watch(() => props.pagination, (val) => {
  if (val) paginations.value = { ...paginations.value, ...val }
}, { immediate: true, deep: true })

function onRequest (p) {
  emit('request', p)
  if (props.pagination) paginations.value = { ...paginations.value, ...props.pagination }
}
function head (name) {
  emit('head', name)
}

// Column visibility
const toggleableColumns = computed(() =>
  (props.columns || []).filter(c => c.name !== 'actions' && c.name !== 'created_at')
)

/**
 * Restore the remembered choice. `known` records which columns the table had
 * when the choice was made, which is what lets us tell a column the user HID
 * from one that simply did not exist yet — the first stays hidden, the second
 * appears. Without that distinction a hidden column comes straight back.
 */
function initialVisible () {
  const all = toggleableColumns.value.map(c => c.name)
  const prefs = readPrefs()
  const saved = prefs?.columns
  if (!Array.isArray(saved)) return all
  const known = Array.isArray(prefs?.known) ? prefs.known : saved
  const kept = saved.filter(n => all.includes(n))
  const brandNew = all.filter(n => !known.includes(n))
  return [...kept, ...brandNew]
}

const localVisible = ref(initialVisible())
watch(localVisible, (v) => writePrefs({
  columns: [...v],
  known: toggleableColumns.value.map(c => c.name),
}), { deep: true })

// Re-read when the key changes (the same table component reused on another route).
watch(prefsKey, () => {
  localVisible.value = initialVisible()
  dense.value = readPrefs()?.dense ?? true
})

watch(() => props.visibleColumns, (v) => {
  // An explicit prop wins only while the user has not chosen for themselves.
  if (v && v.length && !readPrefs()?.columns) localVisible.value = v
})

// Columns can arrive after mount (async schema). Re-derive from the saved
// choice rather than blindly showing everything that turned up.
watch(toggleableColumns, () => {
  const next = initialVisible()
  if (next.join(',') !== localVisible.value.join(',')) localVisible.value = next
})

const effectiveVisible = computed(() => {
  // The page's default set applies until the user makes a choice of their own —
  // after that the remembered choice wins, even when it happens to be "all".
  const chosen = !!readPrefs()?.columns
  if (!chosen && props.visibleColumns && props.visibleColumns.length &&
      localVisible.value.join(',') === toggleableColumns.value.map(c => c.name).join(',')) {
    return props.visibleColumns
  }
  // Always include actions and created_at
  const fixed = (props.columns || []).filter(c => c.name === 'actions' || c.name === 'created_at').map(c => c.name)
  return [...fixed, ...localVisible.value]
})

// Auto-inject date formatters into columns so ISO strings show cleanly everywhere
const processedColumns = computed(() =>
  (props.columns || []).map(col => {
    if (!isDateColumn(col.name)) return col
    return {
      ...col,
      format: (val) => fmtDate(val),
    }
  })
)

// Export helpers
function getExportRows () {
  const cols = processedColumns.value.filter(c => localVisible.value.includes(c.name))
  return {
    headers: cols.map(c => c.label),
    rows: (props.data || []).map(row =>
      cols.map(c => {
        const raw = typeof c.field === 'function' ? c.field(row) : row[c.field || c.name]
        const val = typeof c.format === 'function' ? c.format(raw) : raw
        return val ?? ''
      })
    )
  }
}

function exportCSV () {
  const { headers, rows } = getExportRows()
  const csv = [headers, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a'); a.href = url; a.download = 'export.csv'; a.click()
  URL.revokeObjectURL(url)
}

function exportExcel () {
  const { headers, rows } = getExportRows()
  const html = '<table><tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>' +
    rows.map(r => '<tr>' + r.map(v => `<td>${v}</td>`).join('') + '</tr>').join('') + '</table>'
  const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a'); a.href = url; a.download = 'export.xls'; a.click()
  URL.revokeObjectURL(url)
}

function exportPDF () {
  const { headers, rows } = getExportRows()
  const tableHtml = '<table border="1" style="border-collapse:collapse;width:100%;font-size:12px">' +
    '<thead style="background:#e0f2fe"><tr>' + headers.map(h => `<th style="padding:6px">${h}</th>`).join('') + '</tr></thead>' +
    '<tbody>' + rows.map(r => '<tr>' + r.map(v => `<td style="padding:4px">${v}</td>`).join('') + '</tr>').join('') + '</tbody></table>'
  const win = window.open('', '_blank')
  win.document.write(`<html><head><title>Export</title><style>body{font-family:sans-serif}</style></head><body>${tableHtml}</body></html>`)
  win.document.close(); win.print()
}

function printTable () {
  exportPDF()
}

function copyToClipboardData () {
  const { headers, rows } = getExportRows()
  const text = [headers, ...rows].map(r => r.join('\t')).join('\n')
  qCopy(text).then(() => Notify.create({ type: 'positive', message: 'Copied to clipboard', position: 'bottom' }))
}
</script>
