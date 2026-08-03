<template>
  <div class="col-12">

    <!-- ── Button Bar ─────────────────────────────────────────────── -->
    <div class="ab-bar row justify-between items-center q-mt-xs q-mb-xs">

      <!-- Left group -->
      <div class="row items-center">
        <progress-btn v-if="createPerm && $can(createPerm)" :color="addColor" icon="add" @click="$emit('add')">
          {{ addLabel }}
        </progress-btn>
        <progress-btn v-if="importPerm && $can(importPerm)" color="pink-6" icon="mdi-database-import" @click="showImport = true">
          {{ $t('Import') }}
        </progress-btn>
      </div>

      <!-- Right group -->
      <div class="row items-center">
        <progress-btn v-if="rows.length > 0" color="blue-grey-9" icon="mdi-tune" @click="showSearch = !showSearch">
          {{ $t('AdvanceSearch') }}
        </progress-btn>
        <export-btn v-if="rows.length > 0" :data="getFiltered()" :columns="columns" :filename="filename" />
        <slot name="extra-buttons" />
      </div>
    </div>

    <!-- ── Advanced Search Panel ──────────────────────────────────── -->
    <q-slide-transition>
      <div v-if="showSearch">
        <div class="row q-col-gutter-sm q-pa-sm bg-blue-grey-1 q-my-xs" style="border-radius:10px">
          <div class="col-12 col-sm-4">
            <q-input outlined dense color="blue-grey-9" label-color="blue-grey-9"
              v-model="searchText" :label="$t('Search')" clearable @update:model-value="emitFiltered">
              <template #prepend><q-icon name="search" color="blue-grey-9" /></template>
            </q-input>
          </div>
          <div v-if="hasDate" class="col-6 col-sm-3">
            <q-input outlined dense color="blue-grey-9" label-color="blue-grey-9"
              v-model="dateFrom" type="date" :label="$t('DateFrom')" @update:model-value="emitFiltered">
              <template #prepend><q-icon name="event" color="blue-grey-9" /></template>
            </q-input>
          </div>
          <div v-if="hasDate" class="col-6 col-sm-3">
            <q-input outlined dense color="blue-grey-9" label-color="blue-grey-9"
              v-model="dateTo" type="date" :label="$t('DateTo')" @update:model-value="emitFiltered">
              <template #prepend><q-icon name="event" color="blue-grey-9" /></template>
            </q-input>
          </div>
          <div class="col-auto flex items-end">
            <q-btn flat dense icon="clear" color="grey-6" size="sm" @click="clearSearch" :label="$t('Clear')" />
          </div>
        </div>
      </div>
    </q-slide-transition>

    <!-- ── Import Modal ───────────────────────────────────────────── -->
    <m-modal :showCM="showImport" @update:showCM="showImport = $event" card_style="width: 700px">
      <q-card class="bg-white">
        <n-header icon="mdi-database-import">{{ $t('Import') }}</n-header>
        <q-separator />
        <q-card-section>
          <div class="text-caption text-grey-7 q-mb-sm">
            Upload an Excel (.xlsx) or CSV (.csv) file. The first row must contain column headers.
          </div>
          <q-file outlined dense color="pink-6" label-color="pink-6"
            v-model="importFile" accept=".xlsx,.xls,.csv"
            :label="$t('SelectFile')" @update:model-value="onFileSelect">
            <template #prepend><q-icon name="attach_file" color="pink-6" /></template>
          </q-file>

          <div v-if="importRows.length > 0" class="q-mt-sm">
            <div class="text-caption text-grey-6 q-mb-xs">Preview (first {{ Math.min(importRows.length, 5) }} rows)</div>
            <div style="overflow-x:auto">
              <q-markup-table flat bordered dense class="my_radio_less">
                <thead class="bg-pink-1">
                  <tr>
                    <th v-for="h in importHeaders" :key="h" class="text-left">{{ h }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, i) in importRows.slice(0, 5)" :key="i">
                    <td v-for="h in importHeaders" :key="h">{{ row[h] ?? '' }}</td>
                  </tr>
                </tbody>
              </q-markup-table>
            </div>
            <div class="text-caption text-grey-6 q-mt-xs">{{ importRows.length }} rows ready to import</div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-px-md q-pb-md">
          <q-btn flat :label="$t('Cancel')" color="grey-7" @click="closeImport" />
          <q-btn unelevated color="pink-6" icon="mdi-database-import" :label="$t('Import')"
            :disable="importRows.length === 0" :loading="importing"
            @click="doImport" />
        </q-card-actions>
      </q-card>
    </m-modal>

  </div>
</template>

<script setup>
import { ref, computed, watch, getCurrentInstance } from 'vue'
import * as XLSX from 'xlsx'
import { Notify } from 'quasar'

const { proxy } = getCurrentInstance()

const props = defineProps({
  rows: { type: Array, default: () => [] },
  columns: { type: Array, default: () => [] },
  filename: { type: String, default: 'export' },
  createPerm: { type: String, default: '' },
  importPerm: { type: String, default: '' },
  importUrl: { type: String, default: '' },
  addColor: { type: String, default: 'teal' },
  hasDate: { type: Boolean, default: false },
  dateField: { type: String, default: 'date' },
  // Optional explicit label for the add button (overrides the derived one).
  addLabel: { type: String, default: '' },
})

// "Add <Entity>" derived from the create permission (e.g. project-create → Add
// Project). A few slugs map to nicer names; the rest fall back to a title-cased
// slug so the label always reads well.
const entityOverrides = { tradesman: 'Subcontractor', 'stock-item': 'StockItem', party: 'Party' }
const addLabel = computed(() => {
  if (props.addLabel) return props.addLabel
  const slug = (props.createPerm || '').replace(/-create$/, '')
  if (!slug) return proxy.$t('AddNew')
  const key = entityOverrides[slug] || slug.split('-').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join('')
  let name = proxy.$t(key)
  // $t returns the key itself when there's no translation; for multi-word slugs
  // that PascalCase key reads badly, so fall back to a spaced title-case slug.
  if (name === key && slug.includes('-')) {
    name = slug.split('-').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join(' ')
  }
  return `${proxy.$t('Add')} ${name}`
})

const emit = defineEmits(['add', 'update:filtered', 'imported'])

const showSearch = ref(false)
const searchText = ref('')
const dateFrom = ref('')
const dateTo = ref('')

const showImport = ref(false)
const importFile = ref(null)
const importHeaders = ref([])
const importRows = ref([])
const importing = ref(false)

function getFiltered() {
  let list = props.rows
  if (searchText.value) {
    const q = searchText.value.toLowerCase()
    list = list.filter(r => JSON.stringify(r).toLowerCase().includes(q))
  }
  if (props.hasDate && dateFrom.value) {
    list = list.filter(r => (r[props.dateField] ?? r.date ?? r.created_at ?? '') >= dateFrom.value)
  }
  if (props.hasDate && dateTo.value) {
    list = list.filter(r => (r[props.dateField] ?? r.date ?? r.created_at ?? '') <= dateTo.value)
  }
  return list
}

function emitFiltered() {
  emit('update:filtered', getFiltered())
}

watch(() => props.rows, () => emitFiltered(), { immediate: true })

function clearSearch() {
  searchText.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  emitFiltered()
}

function onFileSelect(file) {
  if (!file) { importHeaders.value = []; importRows.value = []; return }
  const reader = new FileReader()
  reader.onload = (e) => {
    const data = new Uint8Array(e.target.result)
    const wb = XLSX.read(data, { type: 'array' })
    const ws = wb.Sheets[wb.SheetNames[0]]
    const json = XLSX.utils.sheet_to_json(ws, { defval: '' })
    if (json.length === 0) { importHeaders.value = []; importRows.value = []; return }
    importHeaders.value = Object.keys(json[0])
    importRows.value = json
  }
  reader.readAsArrayBuffer(file)
}

async function doImport() {
  if (!props.importUrl) {
    emit('imported', importRows.value)
    closeImport()
    return
  }
  importing.value = true
  try {
    const { api } = await import('@/boot/axios')
    await api.post(props.importUrl, { rows: importRows.value })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Imported successfully' })
    emit('imported', importRows.value)
    closeImport()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Import failed' })
  } finally {
    importing.value = false
  }
}

function closeImport() {
  showImport.value = false
  importFile.value = null
  importHeaders.value = []
  importRows.value = []
}
</script>


