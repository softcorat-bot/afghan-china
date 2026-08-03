<template>
  <q-page class="q-pa-md imp-page">
    <div class="imp-title"><q-icon name="upload_file" size="24px" class="q-mr-sm" />{{ $t('ImportProducts') }}</div>

    <div class="row q-col-gutter-md q-mt-xs">
      <div class="col-12 col-md-5">
        <q-card flat bordered class="imp-card">
          <div class="imp-card__head"><q-icon name="description" size="18px" class="q-mr-xs" />{{ $t('PasteOrUpload') }}</div>
          <div class="q-pa-sm">
            <div class="row q-gutter-sm q-mb-sm">
              <q-btn outline no-caps color="primary" icon="attach_file" :label="$t('ChooseFile')" @click="fileInput.click()" />
              <q-btn flat no-caps color="primary" icon="download" :label="$t('Template')" @click="downloadTemplate" />
              <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="onFile">
            </div>
            <q-input v-model="raw" type="textarea" outlined dense :label="$t('CsvData')" :input-style="{ minHeight: '220px', fontFamily: 'monospace', fontSize: '12px' }" @update:model-value="parse" />
            <div class="text-caption text-grey-6 q-mt-xs">{{ $t('ImportColumnsHint') }}</div>
          </div>
        </q-card>
      </div>

      <div class="col-12 col-md-7">
        <q-card flat bordered class="imp-card">
          <div class="imp-card__head row items-center">
            <q-icon name="preview" size="18px" class="q-mr-xs" />{{ $t('Preview') }}
            <q-badge v-if="rows.length" class="q-ml-sm" color="primary">{{ rows.length }}</q-badge>
            <q-space />
            <q-btn v-if="rows.length" unelevated dense no-caps color="primary" icon="cloud_upload" :label="$t('Import')" :loading="importing" @click="doImport" />
          </div>
          <div v-if="parseError" class="q-pa-md text-negative">{{ parseError }}</div>
          <div v-else-if="!rows.length" class="imp-empty">{{ $t('NothingToPreview') }}</div>
          <div v-else class="imp-table-wrap">
            <q-markup-table flat dense class="imp-table">
              <thead><tr><th v-for="c in previewCols" :key="c" class="text-left">{{ c }}</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in rows.slice(0, 50)" :key="i" :class="{ 'imp-bad': !r.name }">
                  <td v-for="c in previewCols" :key="c">{{ r[c] }}</td>
                </tr>
              </tbody>
            </q-markup-table>
            <div v-if="rows.length > 50" class="text-caption text-grey-5 q-pa-xs">+ {{ rows.length - 50 }} {{ $t('more') }}…</div>
          </div>
        </q-card>

        <q-card v-if="result" flat bordered class="imp-card q-mt-md">
          <div class="imp-card__head"><q-icon name="task_alt" size="18px" class="q-mr-xs" />{{ $t('ImportResult') }}</div>
          <div class="q-pa-sm">
            <div class="row q-col-gutter-sm">
              <div class="col"><stat-card icon="add_circle" :label="$t('Created')" :value="result.created" color="#16A34A" tint="#DCFCE7" /></div>
              <div class="col"><stat-card icon="sync" :label="$t('Updated')" :value="result.updated" color="#175A8C" tint="#E0EDF7" /></div>
              <div class="col" v-if="result.photos"><stat-card icon="image" :label="$t('Photos')" :value="result.photos" color="#7C3AED" tint="#EDE9FE" /></div>
              <div class="col"><stat-card icon="error" :label="$t('Errors')" :value="result.errors.length" color="#DC2626" tint="#FEE2E2" /></div>
            </div>
            <div v-if="result.errors.length" class="q-mt-sm">
              <div v-for="(e, i) in result.errors" :key="i" class="text-caption text-negative">{{ $t('Row') }} {{ e.row }}: {{ e.message }}</div>
            </div>
          </div>
        </q-card>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { ref, computed, getCurrentInstance } from 'vue'
import { useQuasar } from 'quasar'
import { api } from '@/boot/axios'

const $q = useQuasar()
const { proxy } = getCurrentInstance()
const t = (k) => proxy.$t(k)

// `image_url` is fetched by the server after the row is saved and attached
// as the product photo, exactly as downloaded — so a supplier's catalogue
// spreadsheet brings its pictures with it.
const FIELDS = ['name', 'name_fa', 'sku', 'barcode', 'category', 'brand', 'unit', 'cost_price', 'sale_price', 'tax_rate', 'stock_qty', 'min_stock', 'status', 'image_url']

const fileInput = ref(null)
const raw = ref('')
const rows = ref([])
const parseError = ref('')
const importing = ref(false)
const result = ref(null)

const previewCols = computed(() => {
  const present = new Set()
  rows.value.forEach(r => Object.keys(r).forEach(k => present.add(k)))
  return FIELDS.filter(f => present.has(f))
})

// Minimal RFC-4180-ish CSV parser (handles quotes, escaped quotes, commas, CRLF).
function parseCsv (text) {
  const rows = []
  let field = ''
  let row = []
  let inQuotes = false
  for (let i = 0; i < text.length; i++) {
    const c = text[i]
    if (inQuotes) {
      if (c === '"') {
        if (text[i + 1] === '"') { field += '"'; i++ } else { inQuotes = false }
      } else { field += c }
    } else if (c === '"') { inQuotes = true }
    else if (c === ',') { row.push(field); field = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++
      row.push(field); field = ''
      if (row.some(v => v.trim() !== '')) rows.push(row)
      row = []
    } else { field += c }
  }
  if (field !== '' || row.length) { row.push(field); if (row.some(v => v.trim() !== '')) rows.push(row) }
  return rows
}

function parse () {
  parseError.value = ''
  result.value = null
  const text = raw.value.trim()
  if (!text) { rows.value = []; return }
  try {
    const matrix = parseCsv(text)
    if (matrix.length < 2) { rows.value = []; parseError.value = t('NeedHeaderAndRow'); return }
    const header = matrix[0].map(h => h.trim().toLowerCase())
    rows.value = matrix.slice(1).map(cells => {
      const o = {}
      header.forEach((h, idx) => { if (FIELDS.includes(h)) o[h] = (cells[idx] ?? '').trim() })
      return o
    })
  } catch (e) {
    parseError.value = e.message
    rows.value = []
  }
}

function onFile (e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => { raw.value = String(reader.result || ''); parse() }
  reader.readAsText(file)
}

function downloadTemplate () {
  const header = FIELDS.join(',')
  const sample = 'Sample Product,محصول نمونه,SKU-001,7000000001,Grocery,BrandX,pcs,80,120,5,50,10,active,https://example.com/photo.jpg'
  const blob = new Blob([header + '\n' + sample + '\n'], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = 'products-template.csv'; a.click()
  URL.revokeObjectURL(url)
}

async function doImport () {
  if (!rows.value.length) return
  importing.value = true
  result.value = null
  try {
    const payload = rows.value.map(r => {
      const o = { ...r }
      for (const k of ['cost_price', 'sale_price', 'tax_rate', 'stock_qty', 'min_stock']) if (o[k] != null && o[k] !== '') o[k] = Number(o[k])
      return o
    })
    const { data } = await api.post('/products/import', { rows: payload })
    result.value = data
    $q.notify({ message: t('Imported') + `: +${data.created} / ~${data.updated}`, color: 'positive', position: 'top' })
  } catch (e) {
    $q.notify({ message: e?.response?.data?.message || t('SaveFailed'), color: 'negative', position: 'top' })
  } finally { importing.value = false }
}
</script>

<style scoped>
.imp-page { background: #F0F4F8; }
.imp-title { font-size: 20px; font-weight: 800; color: #0F172A; display: flex; align-items: center; }
.imp-card { border-radius: 14px; }
.imp-card__head { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; padding: 12px 14px 6px; }
.imp-empty { color: #94A3B8; text-align: center; padding: 40px; }
.imp-table-wrap { max-height: 360px; overflow: auto; }
.imp-table th { font-weight: 700; color: #475569; font-size: 11px; white-space: nowrap; }
.imp-table td { font-size: 11.5px; white-space: nowrap; }
.imp-bad td { background: #FEF2F2; }
</style>
