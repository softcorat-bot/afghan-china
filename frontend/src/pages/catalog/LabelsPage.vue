<template>
  <q-page class="q-pa-md lbl-page">
    <div class="lbl-toolbar">
      <div class="lbl-title"><q-icon name="qr_code_2" size="24px" class="q-mr-sm" />{{ $t('BarcodeLabels') }}</div>
      <q-space />
      <q-btn outline no-caps color="grey-7" icon="clear_all" :label="$t('Clear')" @click="picked = []" :disable="!picked.length" />
      <q-btn unelevated no-caps color="primary" icon="print" :label="$t('Print')" class="q-ml-sm" :disable="!labels.length" @click="printLabels" />
    </div>

    <div class="row q-col-gutter-md">
      <!-- picker -->
      <div class="col-12 col-md-4 lbl-noprint">
        <q-card flat bordered class="lbl-card">
          <div class="lbl-card__head"><q-icon name="inventory_2" size="18px" class="q-mr-xs" />{{ $t('SelectProducts') }}</div>
          <div class="q-pa-sm">
            <q-input outlined dense v-model="search" :label="$t('Search')" clearable debounce="250">
              <template #prepend><q-icon name="search" /></template>
            </q-input>
            <div class="lbl-list q-mt-sm">
              <div v-for="p in filtered" :key="p.id" class="lbl-item" @click="add(p)">
                <div class="col">
                  <div class="text-weight-medium">{{ p.name }}</div>
                  <div class="text-caption text-grey-6">{{ p.barcode || $t('NoBarcode') }} · {{ money(p.sale_price) }}</div>
                </div>
                <q-icon name="add_circle" color="primary" />
              </div>
              <div v-if="!filtered.length" class="text-grey-5 text-center q-pa-md">{{ $t('NoItemsFound') }}</div>
            </div>
          </div>
        </q-card>

        <q-card v-if="picked.length" flat bordered class="lbl-card q-mt-md">
          <div class="lbl-card__head"><q-icon name="tune" size="18px" class="q-mr-xs" />{{ $t('LabelSettings') }}</div>
          <div class="q-pa-sm">
            <q-select outlined dense v-model="size" :options="sizeOptions" emit-value map-options :label="$t('LabelSize')" />
            <div class="row items-center q-mt-sm">
              <q-toggle v-model="showPrice" :label="$t('ShowPrice')" />
              <q-toggle v-model="showName" :label="$t('ShowName')" />
            </div>
            <q-separator class="q-my-sm" />
            <div v-for="row in picked" :key="row.id" class="row items-center q-mb-xs">
              <div class="col text-caption ellipsis">{{ row.name }}</div>
              <q-input dense outlined type="number" min="1" style="width:80px" v-model.number="row.copies" />
              <q-btn round flat dense size="sm" color="negative" icon="close" @click="remove(row.id)" />
            </div>
            <div class="text-caption text-grey-6 q-mt-xs">{{ labels.length }} {{ $t('LabelsTotal') }}</div>
          </div>
        </q-card>
      </div>

      <!-- preview / print sheet -->
      <div class="col-12 col-md-8">
        <div v-if="!labels.length" class="lbl-empty lbl-noprint">
          <q-icon name="qr_code_2" size="60px" color="grey-3" />
          <div class="text-grey-5 q-mt-sm">{{ $t('SelectProductsToPrint') }}</div>
        </div>
        <div v-else id="lbl-sheet" class="lbl-sheet" :class="`lbl-sheet--${size}`">
          <div v-for="(l, i) in labels" :key="i" class="lbl" :class="`lbl--${size}`">
            <div v-if="showName" class="lbl__name">{{ l.name }}</div>
            <svg :ref="el => setSvg(el, i)" class="lbl__code"></svg>
            <div v-if="showPrice" class="lbl__price">{{ money(l.sale_price) }}</div>
          </div>
        </div>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, getCurrentInstance } from 'vue'
import JsBarcode from 'jsbarcode'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()
const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' AFN'

const products = ref([])
const search = ref('')
const picked = ref([])
const size = ref('md')
const showPrice = ref(true)
const showName = ref(true)

const sizeOptions = [
  { label: proxy.$t('Small') + ' (40×25)', value: 'sm' },
  { label: proxy.$t('Medium') + ' (50×30)', value: 'md' },
  { label: proxy.$t('Large') + ' (60×40)', value: 'lg' },
]

async function load () {
  try { const { data } = await api.get('/products'); products.value = (data || []).filter(p => p.barcode) } catch (_) {}
}
onMounted(load)

const filtered = computed(() => {
  const s = search.value.trim().toLowerCase()
  return products.value.filter(p => !s || (p.name + ' ' + (p.barcode || '')).toLowerCase().includes(s)).slice(0, 40)
})

function add (p) {
  if (!picked.value.find(x => x.id === p.id)) {
    picked.value.push({ id: p.id, name: p.name, barcode: p.barcode, sale_price: p.sale_price, copies: 1 })
  }
}
function remove (id) { picked.value = picked.value.filter(x => x.id !== id) }

const labels = computed(() => {
  const out = []
  for (const row of picked.value) {
    const n = Math.max(1, Math.min(200, Number(row.copies) || 1))
    for (let i = 0; i < n; i++) out.push(row)
  }
  return out
})

const svgs = []
function setSvg (el, i) { if (el) svgs[i] = el }

function renderBarcodes () {
  nextTick(() => {
    const opts = { sm: { w: 1, h: 30, f: 9 }, md: { w: 1.4, h: 40, f: 11 }, lg: { w: 1.8, h: 52, f: 13 } }[size.value]
    labels.value.forEach((l, i) => {
      const el = svgs[i]
      if (!el) return
      try {
        JsBarcode(el, String(l.barcode), { format: 'CODE128', width: opts.w, height: opts.h, fontSize: opts.f, margin: 2, displayValue: true })
      } catch (_) { /* invalid barcode value */ }
    })
  })
}
watch([labels, size], renderBarcodes, { flush: 'post' })

function printLabels () { renderBarcodes(); setTimeout(() => window.print(), 120) }
</script>

<style scoped>
.lbl-page { background: #F0F4F8; }
.lbl-toolbar { display: flex; align-items: center; margin-bottom: 14px; }
.lbl-title { font-size: 20px; font-weight: 800; color: #0F172A; display: flex; align-items: center; }
.lbl-card { border-radius: 14px; }
.lbl-card__head { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; padding: 12px 14px 6px; }
.lbl-list { max-height: 340px; overflow-y: auto; }
.lbl-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 10px; cursor: pointer; border: 1px solid #EEF2F6; margin-bottom: 6px; }
.lbl-item:hover { border-color: var(--q-primary); background: #F8FAFC; }
.lbl-empty { height: 320px; display: flex; flex-direction: column; align-items: center; justify-content: center; }

.lbl-sheet { display: flex; flex-wrap: wrap; gap: 6px; background: #fff; padding: 10px; border-radius: 12px; border: 1px solid #E2E8F0; }
.lbl { border: 1px dashed #CBD5E1; border-radius: 4px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3px; text-align: center; overflow: hidden; }
.lbl--sm { width: 151px; height: 94px; }
.lbl--md { width: 189px; height: 113px; }
.lbl--lg { width: 227px; height: 151px; }
.lbl__name { font-size: 11px; font-weight: 700; color: #0F172A; line-height: 1.1; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lbl__code { max-width: 100%; }
.lbl__price { font-size: 12px; font-weight: 800; color: #175A8C; }

@media print {
  .lbl-noprint, .lbl-toolbar { display: none !important; }
  .lbl-page { background: #fff; padding: 0 !important; }
  .lbl-sheet { border: none; padding: 0; gap: 2mm; }
  .lbl { border: 1px solid #E2E8F0; page-break-inside: avoid; }
}
</style>
