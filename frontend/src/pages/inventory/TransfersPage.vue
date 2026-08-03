<template>
  <q-page class="tf-page q-pa-md">

    <!-- ── VIP hero ─────────────────────────────────────────────── -->
    <div class="tf-hero q-mb-md">
      <div class="tf-hero__left">
        <div class="tf-hero__eyebrow"><q-icon name="warehouse" size="15px" /> {{ $t('WarehouseOperations') }}</div>
        <div class="tf-hero__title">{{ $t('InventoryControl') }}</div>
        <div class="tf-hero__sub">{{ $t('InventoryControlHint') }}</div>
      </div>
      <div class="tf-hero__stats">
        <div class="tf-hstat">
          <div class="tf-hstat__val">{{ fmtQ(totalWarehouse) }}</div>
          <div class="tf-hstat__lbl">{{ $t('UnitsInWarehouse') }}</div>
        </div>
        <div class="tf-hstat">
          <div class="tf-hstat__val">{{ fmtQ(totalShop) }}</div>
          <div class="tf-hstat__lbl">{{ $t('UnitsInShop') }}</div>
        </div>
        <div class="tf-hstat tf-hstat--gold">
          <div class="tf-hstat__val">{{ history.length }}</div>
          <div class="tf-hstat__lbl">{{ $t('RecentTransfers') }}</div>
        </div>
      </div>
    </div>

    <q-card flat class="tf-card">
      <q-tabs v-model="tab" dense no-caps class="tf-tabs" active-color="amber-8" indicator-color="amber-8" align="left">
        <q-tab v-if="$can('transfer-list')" name="transfer" icon="sync_alt" :label="$t('NewTransfer')" />
        <q-tab v-if="$can('stock-adjustment-list')" name="adjust" icon="tune" :label="$t('StockAdjustments')" />
        <q-tab name="history" icon="history" :label="$t('TransferHistory')" />
      </q-tabs>
      <q-separator />

      <!-- ═══ NEW TRANSFER ═══ -->
      <div v-show="tab === 'transfer'" class="q-pa-md">
        <!-- Movement mode — three VIP cards -->
        <div class="tf-modes q-mb-md">
          <button v-for="m in modes" :key="m.value" class="tf-mode" :class="[{ 'tf-mode--on': direction === m.value }, 'tf-mode--' + m.value]" @click="direction = m.value">
            <span class="tf-mode__icon"><q-icon :name="m.icon" size="20px" /></span>
            <span class="tf-mode__txt">
              <b>{{ $t(m.label) }}</b>
              <small>{{ $t(m.caption) }}</small>
            </span>
            <span class="tf-mode__check"><q-icon name="check_circle" size="16px" /></span>
          </button>
        </div>

        <!-- Inbound origin: person / company / country + destination -->
        <transition name="tfslide">
          <div v-if="direction === 'inbound'" class="tf-inbound q-mb-md">
            <q-icon name="public" size="18px" class="tf-inbound__globe" />
            <q-select outlined dense v-model="inboundSource" :options="sourceOptions" use-input new-value-mode="add-unique" hide-dropdown-icon
              :label="$t('InboundSource')" class="tf-inbound__src" @filter="filterSources" bg-color="white">
              <template #prepend><q-icon name="local_shipping" color="green-7" /></template>
            </q-select>
            <q-btn-toggle v-model="inboundDest" no-caps unelevated toggle-color="green-7" toggle-text-color="white" class="tf-toggle"
              :options="[
                { label: $t('Warehouse'), value: 'warehouse', icon: 'warehouse' },
                { label: $t('Shop'), value: 'shop', icon: 'storefront' },
              ]" />
          </div>
        </transition>

        <div class="row q-col-gutter-sm items-center q-mb-md">
          <div class="col-12 col-md">
            <q-input outlined dense v-model="scan" :label="$t('ScanBarcodeToAdd')" class="tf-scan" @keyup.enter="onScan" autofocus ref="scanInput">
              <template #prepend><q-icon name="qr_code_scanner" color="amber-8" /></template>
              <template #append><q-badge color="blue-grey-2" text-color="blue-grey-8">{{ $t('EnterToAdd') }}</q-badge></template>
            </q-input>
          </div>
          <div class="col-6 col-md-3">
            <q-input outlined dense v-model="search" :label="$t('Search')" clearable>
              <template #prepend><q-icon name="search" color="amber-8" /></template>
            </q-input>
          </div>
        </div>

        <!-- Product list with qty inputs -->
        <div class="tf-list">
          <div class="tf-row tf-row--head">
            <div>{{ $t('Product') }}</div>
            <div class="text-center">{{ $t('Warehouse') }}</div>
            <div class="text-center">{{ $t('Shop') }}</div>
            <div>{{ $t('TransferQty') }}</div>
          </div>

          <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="34px" /></div>

          <div v-for="p in visibleProducts" :key="p.id" class="tf-row" :class="{ 'tf-row--hit': hitId === p.id, 'tf-row--picked': Number(drafts[p.id]) > 0 }" :ref="el => rowRefs[p.id] = el">
            <div class="tf-row__prod">
              <div class="tf-thumb">
                <img v-if="p.image_url" :src="assetUrl(p.image_url)" :alt="p.name">
                <q-icon v-else name="inventory_2" size="16px" color="blue-grey-4" />
              </div>
              <div class="min-w-0">
                <div class="tf-row__name">{{ p.name }}</div>
                <div class="tf-row__meta"><q-icon name="qr_code_2" size="11px" /> {{ p.barcode || p.sku || '—' }}</div>
              </div>
            </div>
            <div class="text-center"><q-badge :color="Number(p.warehouse_qty) > 0 ? 'indigo-6' : 'blue-grey-4'">{{ Number(p.warehouse_qty) }}</q-badge></div>
            <div class="text-center"><q-badge :color="Number(p.stock_qty) > 0 ? 'green-7' : 'red-5'">{{ Number(p.stock_qty) }}</q-badge></div>
            <div class="tf-row__qty">
              <q-btn dense flat round size="sm" icon="remove" color="blue-grey-6" :disable="!Number(drafts[p.id])" @click="bump(p, -1)" />
              <q-input outlined dense type="number" min="0" v-model.number="drafts[p.id]" class="tf-qty-input" :placeholder="'0'" />
              <q-btn dense flat round size="sm" icon="add" :color="direction === 'inbound' ? 'green-7' : 'amber-8'" :disable="direction !== 'inbound' && Number(drafts[p.id] || 0) >= sourceQty(p)" @click="bump(p, 1)" />
              <span v-if="direction !== 'inbound'" class="tf-row__max">/ {{ fmtQ(sourceQty(p)) }}</span>
              <q-icon v-else name="all_inclusive" size="13px" color="green-6" />
            </div>
          </div>
          <div v-if="!loading && !visibleProducts.length" class="q-py-xl text-center text-grey-5">{{ $t('NoRecordFound') }}</div>
        </div>

        <!-- Sticky submit bar -->
        <div v-if="pickedCount > 0" class="tf-submitbar">
          <div class="tf-submitbar__info">
            <b>{{ pickedCount }}</b> {{ $t('Products') }} · <b>{{ fmtQ(pickedUnits) }}</b> {{ $t('Units') }}
            <q-btn dense flat no-caps size="sm" color="red-5" icon="backspace" :label="$t('Clear')" @click="clearDrafts" class="q-ml-sm" />
          </div>
          <q-input outlined dense v-model="note" :label="$t('Note')" class="tf-submitbar__note" bg-color="white" />
          <q-btn unelevated no-caps :loading="submitting" class="tf-submitbar__btn" :class="{ 'tf-submitbar__btn--in': direction === 'inbound' }"
            :icon="direction === 'inbound' ? 'flight_land' : (direction === 'to_store' ? 'south' : 'north')"
            :label="direction === 'inbound' ? $t('ReceiveStock') : (direction === 'to_store' ? $t('TransferToStore') : $t('TransferToWarehouse'))"
            :disable="direction === 'inbound' && !String(inboundSource || '').trim()"
            @click="submit" />
        </div>
      </div>

      <!-- ═══ ADJUSTMENTS — corrections that CHANGE total stock ═══ -->
      <div v-show="tab === 'adjust'" class="q-pa-md">
        <div class="row q-col-gutter-sm items-center q-mb-md">
          <div class="col-12 col-md-6">
            <q-banner dense rounded class="bg-amber-1 text-brown-9">
              <template #avatar><q-icon name="info" color="amber-9" /></template>
              {{ $t('AdjustVsTransferHint') }}
            </q-banner>
          </div>
          <div class="col-12 col-md-6">
            <q-input outlined dense v-model="search" :label="$t('Search')" clearable>
              <template #prepend><q-icon name="search" color="amber-8" /></template>
            </q-input>
          </div>
        </div>

        <div class="tf-list">
          <div class="tf-row tf-row--head">
            <div>{{ $t('Product') }}</div>
            <div class="text-center">{{ $t('Warehouse') }}</div>
            <div class="text-center">{{ $t('Shop') }}</div>
            <div>{{ $t('Adjust') }}</div>
          </div>
          <div v-for="p in visibleProducts" :key="'adj' + p.id" class="tf-row">
            <div class="tf-row__prod">
              <div class="tf-thumb">
                <img v-if="p.image_url" :src="assetUrl(p.image_url)" :alt="p.name">
                <q-icon v-else name="inventory_2" size="16px" color="blue-grey-4" />
              </div>
              <div class="min-w-0">
                <div class="tf-row__name">{{ p.name }}</div>
                <div class="tf-row__meta"><q-icon name="qr_code_2" size="11px" /> {{ p.barcode || p.sku || '—' }}</div>
              </div>
            </div>
            <div class="text-center"><q-badge :color="Number(p.warehouse_qty) > 0 ? 'indigo-6' : 'blue-grey-4'">{{ Number(p.warehouse_qty) }}</q-badge></div>
            <div class="text-center"><q-badge :color="Number(p.stock_qty) > 0 ? 'green-7' : 'red-5'">{{ Number(p.stock_qty) }}</q-badge></div>
            <div>
              <q-btn dense unelevated no-caps size="sm" color="green-7" icon="add" :label="$t('Increase')" class="q-mr-xs" @click="openAdjust(p, 'increase')" />
              <q-btn dense outline no-caps size="sm" color="red-5" icon="remove" :label="$t('Decrease')" @click="openAdjust(p, 'decrease')" />
            </div>
          </div>
          <div v-if="!visibleProducts.length" class="q-py-xl text-center text-grey-5">{{ $t('NoRecordFound') }}</div>
        </div>
      </div>

      <!-- ═══ HISTORY ═══ -->
      <div v-show="tab === 'history'" class="q-pa-md">
        <div class="q-mb-md">
          <q-btn-toggle v-model="historySource" no-caps unelevated toggle-color="amber-8" toggle-text-color="white" class="tf-toggle"
            :options="[
              { label: $t('WarehouseTransfers'), value: 'transfers', icon: 'sync_alt' },
              { label: $t('StockAdjustments'), value: 'adjustments', icon: 'tune' },
            ]" @update:model-value="historySource === 'adjustments' && loadAdjustments()" />
        </div>

        <div v-show="historySource === 'transfers'">
        <div class="row q-col-gutter-sm q-mb-md">
          <div class="col-6 col-md-3">
            <q-select outlined dense v-model="hf.direction" :options="[
              { label: $t('WarehouseToStore'), value: 'to_store' },
              { label: $t('StoreToWarehouse'), value: 'to_warehouse' },
              { label: $t('Inbound'), value: 'inbound' },
            ]" emit-value map-options :label="$t('Direction')" clearable @update:model-value="loadHistory">
              <template #prepend><q-icon name="sync_alt" color="amber-8" /></template>
            </q-select>
          </div>
          <div class="col-6 col-md-2"><q-input outlined dense v-model="hf.from" type="date" :label="$t('From')" @update:model-value="loadHistory" /></div>
          <div class="col-6 col-md-2"><q-input outlined dense v-model="hf.to" type="date" :label="$t('To')" @update:model-value="loadHistory" /></div>
        </div>

        <div v-if="loadingHistory" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="34px" /></div>
        <div v-else-if="!history.length" class="q-py-xl text-center text-grey-6">
          <q-icon name="sync_alt" size="30px" color="amber-8" /><br>{{ $t('NoRecordFound') }}
        </div>

        <div v-for="t in history" :key="t.id" class="tf-hist" :class="{ 'tf-hist--open': expanded === t.id }">
          <div class="tf-hist__row" @click="expanded = expanded === t.id ? null : t.id">
            <span class="tf-hist__dir" :class="t.direction === 'inbound' ? 'tf-hist__dir--inb' : (t.direction === 'to_store' ? 'tf-hist__dir--in' : 'tf-hist__dir--out')">
              <q-icon :name="t.direction === 'inbound' ? 'flight_land' : (t.direction === 'to_store' ? 'south' : 'north')" size="14px" />
            </span>
            <b class="tf-hist__ref">{{ t.reference }}</b>
            <q-chip dense square size="sm" :color="t.direction === 'inbound' ? 'green-7' : (t.direction === 'to_store' ? 'amber-8' : 'indigo-6')" text-color="white" class="q-ma-none">
              {{ t.direction === 'inbound' ? $t('Inbound') + ' → ' + $t(t.destination === 'shop' ? 'Shop' : 'Warehouse') : (t.direction === 'to_store' ? $t('WarehouseToStore') : $t('StoreToWarehouse')) }}
            </q-chip>
            <q-chip v-if="t.source" dense square size="sm" color="green-1" text-color="green-9" icon="public" class="q-ma-none">{{ t.source }}</q-chip>
            <span class="tf-hist__meta">{{ t.lines_count }} {{ $t('Lines') }} · {{ fmtQ(t.total_qty) }} {{ $t('Units') }}</span>
            <q-space />
            <span class="tf-hist__meta">{{ t.user?.name || '—' }} · {{ (t.created_at || '').replace('T', ' ').slice(0, 16) }}</span>
            <q-icon :name="expanded === t.id ? 'expand_less' : 'expand_more'" size="18px" color="blue-grey-5" />
          </div>
          <div v-if="expanded === t.id" class="tf-hist__items">
            <div v-if="t.note" class="tf-hist__note"><q-icon name="sticky_note_2" size="13px" /> {{ t.note }}</div>
            <div v-for="i in t.items" :key="i.id" class="tf-hist__item">
              <span class="min-w-0">{{ i.name }}</span>
              <span class="tf-hist__barcode">{{ i.barcode || '—' }}</span>
              <b>× {{ Number(i.qty) }}</b>
            </div>
          </div>
        </div>
        </div>

        <!-- Adjustments audit -->
        <div v-show="historySource === 'adjustments'">
          <div v-if="loadingAdjustments" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="34px" /></div>
          <div v-else-if="!adjustments.length" class="q-py-xl text-center text-grey-6">{{ $t('NoRecordFound') }}</div>
          <div v-for="a in adjustments" :key="'a' + a.id" class="tf-hist">
            <div class="tf-hist__row" style="cursor:default">
              <span class="tf-hist__dir" :class="a.type === 'increase' ? 'tf-hist__dir--in' : 'tf-hist__dir--out'">
                <q-icon :name="a.type === 'increase' ? 'add' : 'remove'" size="14px" />
              </span>
              <b class="tf-hist__ref">{{ a.product?.name || '#' + a.product_id }}</b>
              <q-chip dense square size="sm" :color="a.type === 'increase' ? 'green-7' : 'red-5'" text-color="white" class="q-ma-none">
                {{ a.type === 'increase' ? '+' : '−' }}{{ Number(a.qty) }}
              </q-chip>
              <q-chip dense square size="sm" :color="a.location === 'warehouse' ? 'indigo-6' : 'teal-7'" text-color="white" class="q-ma-none">
                {{ $t(a.location === 'warehouse' ? 'Warehouse' : 'Shop') }}
              </q-chip>
              <span class="tf-hist__meta">{{ Number(a.stock_before) }} → <b>{{ Number(a.stock_after) }}</b><template v-if="a.reason"> · {{ a.reason }}</template></span>
              <q-space />
              <span class="tf-hist__meta">{{ a.user?.name || '—' }} · {{ (a.created_at || '').replace('T', ' ').slice(0, 16) }}</span>
            </div>
          </div>
        </div>
      </div>
    </q-card>

    <!-- ── Adjust dialog: signed correction with reason + live preview ── -->
    <q-dialog v-model="adjDlg">
      <q-card class="tf-adj">
        <q-card-section class="row items-center q-gutter-sm q-pb-sm">
          <q-avatar :icon="adjForm.type === 'increase' ? 'add' : 'remove'" :color="adjForm.type === 'increase' ? 'green-1' : 'red-1'" :text-color="adjForm.type === 'increase' ? 'green-8' : 'red-6'" />
          <div class="min-w-0">
            <div class="text-weight-bold">{{ adjForm.product?.name }}</div>
            <div class="text-caption text-grey-7">{{ $t(adjForm.type === 'increase' ? 'IncreaseStock' : 'DecreaseStock') }}</div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm">
          <q-btn-toggle v-model="adjForm.location" no-caps unelevated spread toggle-color="amber-8" toggle-text-color="white" class="tf-toggle"
            :options="[
              { label: $t('Shop') + ' (' + Number(adjForm.product?.stock_qty ?? 0) + ')', value: 'shop' },
              { label: $t('Warehouse') + ' (' + Number(adjForm.product?.warehouse_qty ?? 0) + ')', value: 'warehouse' },
            ]" />
          <div class="row items-center q-gutter-sm">
            <q-btn round dense unelevated color="blue-grey-2" text-color="blue-grey-9" icon="remove" @click="adjForm.qty = Math.max(1, Number(adjForm.qty || 1) - 1)" />
            <q-input outlined dense type="number" min="1" v-model.number="adjForm.qty" class="col" input-class="text-center text-h6" />
            <q-btn round dense unelevated color="amber-8" text-color="white" icon="add" @click="adjForm.qty = Number(adjForm.qty || 0) + 1" />
          </div>
          <div class="row q-gutter-xs">
            <q-chip v-for="n in [5, 10, 25, 50]" :key="n" dense clickable color="blue-grey-1" text-color="blue-grey-8" @click="adjForm.qty = n">+{{ n }}</q-chip>
          </div>
          <q-select outlined dense v-model="adjForm.reason" :options="reasonOptions" :label="$t('Reason')" emit-value map-options />
          <q-input outlined dense v-model="adjForm.note" :label="$t('Note')" autogrow />
          <q-banner dense rounded :class="newStockPreview < 0 ? 'bg-red-1 text-red-8' : 'bg-blue-grey-1 text-blue-grey-9'">
            {{ $t('NewStock') }}: <b>{{ newStockPreview }}</b>
            <span v-if="newStockPreview < 0"> — {{ $t('BelowZeroBlocked') }}</span>
          </q-banner>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps :color="adjForm.type === 'increase' ? 'green-7' : 'red-5'"
            :icon="adjForm.type === 'increase' ? 'add' : 'remove'"
            :label="$t(adjForm.type === 'increase' ? 'IncreaseStock' : 'DecreaseStock')"
            :loading="adjSaving" :disable="newStockPreview < 0 || !Number(adjForm.qty)" @click="saveAdjust" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, nextTick } from 'vue'
import { Notify } from 'quasar'
import { api, assetUrl } from '@/boot/axios'

const tab = ref('transfer')
const direction = ref('to_store')

// ── Movement modes ──
const modes = [
  { value: 'to_store', icon: 'south', label: 'WarehouseToStore', caption: 'StockTheShopFloor' },
  { value: 'to_warehouse', icon: 'north', label: 'StoreToWarehouse', caption: 'SendBackToReserve' },
  { value: 'inbound', icon: 'flight_land', label: 'InboundReceive', caption: 'FromPersonCompanyCountry' },
]

// ── Inbound origin ──
const inboundSource = ref(null)
const inboundDest = ref('warehouse')
const allSources = ref([])
const sourceOptions = ref([])
function filterSources (val, update) {
  update(() => {
    const n = (val || '').toLowerCase()
    sourceOptions.value = n ? allSources.value.filter(s => s.toLowerCase().includes(n)) : allSources.value
  })
}
async function loadSources () {
  const base = ['China — Guangzhou', 'China — Yiwu', 'Dubai (UAE)', 'Pakistan — Karachi', 'Iran — Mashhad', 'Uzbekistan — Tashkent', 'Local market']
  try {
    const { data } = await api.get('/suppliers')
    const sup = (Array.isArray(data) ? data : []).map(s => s.name).filter(Boolean)
    allSources.value = [...sup, ...base]
  } catch (_) { allSources.value = base }
  sourceOptions.value = allSources.value
}
const products = ref([])
const drafts = reactive({})
const loading = ref(false)
const submitting = ref(false)
const search = ref('')
const scan = ref('')
const note = ref('')
const hitId = ref(null)
const rowRefs = reactive({})
const scanInput = ref(null)

const history = ref([])
const loadingHistory = ref(false)
const expanded = ref(null)
const hf = reactive({ direction: null, from: '', to: '' })
const historySource = ref('transfers')

// ── Adjustments (merged Stock Adjustments module) ──
const adjDlg = ref(false)
const adjSaving = ref(false)
const adjustments = ref([])
const loadingAdjustments = ref(false)
const adjForm = reactive({ product: null, type: 'increase', location: 'shop', qty: 1, reason: 'recount', note: '' })
const reasonOptions = [
  { label: 'Recount', value: 'recount' },
  { label: 'Damage', value: 'damage' },
  { label: 'Theft / Loss', value: 'theft' },
  { label: 'Customer return', value: 'return' },
  { label: 'Correction', value: 'correction' },
]

const newStockPreview = computed(() => {
  const p = adjForm.product
  if (!p) return 0
  const cur = adjForm.location === 'warehouse' ? Number(p.warehouse_qty || 0) : Number(p.stock_qty || 0)
  const d = Number(adjForm.qty) || 0
  return adjForm.type === 'increase' ? cur + d : cur - d
})

function openAdjust (p, type) {
  Object.assign(adjForm, { product: p, type, location: 'shop', qty: 1, reason: 'recount', note: '' })
  adjDlg.value = true
}

async function saveAdjust () {
  adjSaving.value = true
  try {
    await api.post('/stock-adjustments', {
      product_id: adjForm.product.id,
      type: adjForm.type,
      location: adjForm.location,
      qty: Number(adjForm.qty),
      reason: adjForm.reason,
      note: adjForm.note || null,
    })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'tune', message: 'Stock adjusted' })
    adjDlg.value = false
    load()
    if (historySource.value === 'adjustments') loadAdjustments()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Adjustment failed' })
  } finally { adjSaving.value = false }
}

async function loadAdjustments () {
  loadingAdjustments.value = true
  try {
    const { data } = await api.get('/stock-adjustments')
    adjustments.value = data
  } finally { loadingAdjustments.value = false }
}

function fmtQ (v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }) }
const totalWarehouse = computed(() => products.value.reduce((s, p) => s + Number(p.warehouse_qty || 0), 0))
const totalShop = computed(() => products.value.reduce((s, p) => s + Number(p.stock_qty || 0), 0))

const visibleProducts = computed(() => {
  const n = (search.value || '').toLowerCase()
  let list = products.value
  if (n) list = list.filter(p => (p.name || '').toLowerCase().includes(n) || (p.barcode || '').includes(n) || (p.sku || '').toLowerCase().includes(n))
  return list
})

function sourceQty (p) {
  if (direction.value === 'inbound') return Infinity // receiving new stock — no cap
  return direction.value === 'to_store' ? Number(p.warehouse_qty || 0) : Number(p.stock_qty || 0)
}

const pickedCount = computed(() => products.value.filter(p => Number(drafts[p.id]) > 0).length)
const pickedUnits = computed(() => products.value.reduce((s, p) => s + (Number(drafts[p.id]) || 0), 0))

function bump (p, n) {
  const cur = Number(drafts[p.id]) || 0
  drafts[p.id] = Math.max(0, Math.min(sourceQty(p), cur + n))
}
function clearDrafts () { for (const k of Object.keys(drafts)) drafts[k] = 0 }

// ── Barcode scanner: find the product, +1 its qty, flash + scroll to it ──
function onScan () {
  const code = (scan.value || '').trim()
  scan.value = ''
  if (!code) return
  const p = products.value.find(x => x.barcode === code || x.sku === code)
  if (!p) {
    Notify.create({ type: 'negative', icon: 'qr_code_scanner', message: `${code} — not found` })
    return
  }
  if (direction.value !== 'inbound' && sourceQty(p) <= (Number(drafts[p.id]) || 0)) {
    Notify.create({ type: 'warning', icon: 'inventory_2', message: `${p.name}: no more stock at the source` })
    return
  }
  drafts[p.id] = (Number(drafts[p.id]) || 0) + 1
  search.value = ''
  hitId.value = p.id
  nextTick(() => {
    rowRefs[p.id]?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
    setTimeout(() => { if (hitId.value === p.id) hitId.value = null }, 1100)
  })
}

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/products')
    products.value = (data || []).filter(p => p.track_inventory)
  } finally { loading.value = false }
}

async function loadHistory () {
  loadingHistory.value = true
  try {
    const params = {}
    for (const k of ['direction', 'from', 'to']) if (hf[k]) params[k] = hf[k]
    const { data } = await api.get('/stock-transfers', { params })
    history.value = data
  } finally { loadingHistory.value = false }
}

async function submit () {
  const items = products.value
    .filter(p => Number(drafts[p.id]) > 0)
    .map(p => ({ product_id: p.id, qty: Number(drafts[p.id]) }))
  if (!items.length) return
  submitting.value = true
  try {
    const payload = { direction: direction.value, note: note.value || null, items }
    if (direction.value === 'inbound') {
      payload.source = String(inboundSource.value || '').trim()
      payload.destination = inboundDest.value
    }
    const { data } = await api.post('/stock-transfers', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'sync_alt', message: `${data.reference} — ${fmtQ(data.total_qty)} units transferred` })
    clearDrafts(); note.value = ''
    load(); loadHistory()
    scanInput.value?.focus?.()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Transfer failed' })
  } finally { submitting.value = false }
}

watch(direction, () => clearDrafts())

onMounted(() => { load(); loadHistory(); loadSources() })
</script>

<style scoped>
.tf-page { background: #F0F4F8; padding-bottom: 90px; }

/* Hero */
.tf-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.tf-hero__left { flex: 1 1 280px; }
.tf-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.tf-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.tf-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 4px; max-width: 520px; }
.tf-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; }
.tf-hstat {
  min-width: 120px; padding: 10px 14px; border-radius: 14px;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.tf-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.tf-hstat__val { font-size: 17px; font-weight: 900; }
.tf-hstat--gold .tf-hstat__val { color: #F3D48B; }
.tf-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }

.tf-card { border-radius: 18px; border: 1.5px solid #E7ECF3; overflow: hidden; }
.tf-tabs { background: #fff; }
.tf-toggle { border: 1.5px solid #E7ECF3; border-radius: 10px; }
.tf-scan :deep(.q-field__control) { border-radius: 10px; }

/* Rows */
.tf-list { border: 1.5px solid #EEF2F7; border-radius: 14px; overflow: hidden; }
.tf-row {
  display: grid; grid-template-columns: minmax(200px, 2fr) 0.7fr 0.7fr minmax(210px, 1.3fr);
  gap: 10px; align-items: center; padding: 7px 14px; border-bottom: 1px solid #F1F5F9;
  transition: background 0.4s ease;
}
.tf-row--head {
  background: #0E2A47; color: #F3D48B; font-size: 11px; font-weight: 800;
  letter-spacing: 0.6px; text-transform: uppercase; padding: 10px 14px;
}
.tf-row--hit { background: #FEF7E8; }
.tf-row--picked { background: #F8FBF5; }
.tf-row__prod { display: flex; align-items: center; gap: 10px; min-width: 0; }
.tf-thumb {
  width: 36px; height: 36px; border-radius: 10px; overflow: hidden; flex-shrink: 0;
  border: 1.5px solid #E7ECF3; background: #F1F5F9;
  display: flex; align-items: center; justify-content: center;
}
.tf-thumb img { width: 100%; height: 100%; object-fit: cover; }
.tf-row__name { font-size: 12.5px; font-weight: 700; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tf-row__meta { font-size: 10.5px; color: #94A3B8; }
.tf-row__qty { display: flex; align-items: center; gap: 4px; }
.tf-qty-input { width: 86px; }
.tf-qty-input :deep(.q-field__control) { border-radius: 9px; }
.tf-row__max { font-size: 10.5px; color: #94A3B8; white-space: nowrap; }

/* Sticky submit bar */
.tf-submitbar {
  position: sticky; bottom: 14px; z-index: 5; margin-top: 14px;
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  background: linear-gradient(120deg, #0E2A47, #17517F);
  border: 1px solid rgba(243, 212, 139, 0.4); border-radius: 16px;
  padding: 10px 16px; color: #fff;
  box-shadow: 0 18px 36px -18px rgba(14, 42, 71, 0.9);
}
.tf-submitbar__info { font-size: 13px; }
.tf-submitbar__info b { color: #F3D48B; }
.tf-submitbar__note { flex: 1 1 200px; }
.tf-submitbar__btn {
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
  font-weight: 900; border-radius: 11px; padding: 8px 18px;
}

/* History */
.tf-hist { border: 1.5px solid #EEF2F7; border-radius: 12px; margin-bottom: 8px; overflow: hidden; }
.tf-hist--open { border-color: #F3D48B; }
.tf-hist__row { display: flex; align-items: center; gap: 10px; padding: 9px 14px; cursor: pointer; flex-wrap: wrap; }
.tf-hist__row:hover { background: #F8FAFC; }
.tf-hist__dir {
  width: 26px; height: 26px; border-radius: 8px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
}
.tf-hist__dir--in { background: #FEF3C7; color: #B45309; }
.tf-hist__dir--out { background: #E0E7FF; color: #4338CA; }
.tf-hist__ref { font-size: 13px; color: #0F172A; }
.tf-hist__meta { font-size: 11px; color: #94A3B8; }
.tf-hist__items { border-top: 1px dashed #EEF2F7; padding: 8px 14px; background: #FBFDFF; }
.tf-hist__note { font-size: 11.5px; color: #B45309; margin-bottom: 6px; }
.tf-hist__item { display: flex; align-items: center; gap: 12px; font-size: 12px; color: #334155; padding: 3px 0; }
.tf-hist__item b { margin-inline-start: auto; color: #0E2A47; }
.tf-hist__barcode { font-size: 10.5px; color: #94A3B8; }

/* ── Movement mode cards — the VIP selector ── */
.tf-modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.tf-mode {
  position: relative; display: flex; align-items: center; gap: 12px; text-align: start;
  background: #fff; border: 1.5px solid #E7ECF3; border-radius: 16px;
  padding: 14px 16px; cursor: pointer; font-family: inherit;
  transition: all 0.22s ease; overflow: hidden;
}
.tf-mode::before {
  content: ''; position: absolute; inset: 0; opacity: 0; transition: opacity 0.25s ease;
}
.tf-mode--to_store::before { background: linear-gradient(120deg, rgba(243,212,139,0.16), rgba(200,134,45,0.10)); }
.tf-mode--to_warehouse::before { background: linear-gradient(120deg, rgba(99,102,241,0.12), rgba(67,56,202,0.08)); }
.tf-mode--inbound::before { background: linear-gradient(120deg, rgba(34,197,94,0.13), rgba(21,128,61,0.08)); }
.tf-mode:hover { transform: translateY(-2px); box-shadow: 0 14px 26px -18px rgba(14, 42, 71, 0.55); }
.tf-mode--on { border-color: transparent; box-shadow: 0 16px 30px -18px rgba(14, 42, 71, 0.6); }
.tf-mode--on::before { opacity: 1; }
.tf-mode--on.tf-mode--to_store { border-color: #C8862D; }
.tf-mode--on.tf-mode--to_warehouse { border-color: #6366F1; }
.tf-mode--on.tf-mode--inbound { border-color: #16A34A; }
.tf-mode__icon {
  position: relative; width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  transition: transform 0.22s ease;
}
.tf-mode--to_warehouse .tf-mode__icon { color: #C7D2FE; }
.tf-mode--inbound .tf-mode__icon { color: #86EFAC; }
.tf-mode:hover .tf-mode__icon { transform: scale(1.08) rotate(-4deg); }
.tf-mode__txt { position: relative; min-width: 0; }
.tf-mode__txt b { display: block; font-size: 13.5px; color: #0F172A; }
.tf-mode__txt small { font-size: 10.5px; color: #94A3B8; }
.tf-mode__check { position: relative; margin-inline-start: auto; color: #16A34A; opacity: 0; transform: scale(0.6); transition: all 0.22s ease; }
.tf-mode--on .tf-mode__check { opacity: 1; transform: none; }
.tf-mode--on.tf-mode--to_store .tf-mode__check { color: #C8862D; }
.tf-mode--on.tf-mode--to_warehouse .tf-mode__check { color: #6366F1; }

/* Inbound origin bar */
.tf-inbound {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  background: linear-gradient(120deg, #F0FDF4, #ECFDF5);
  border: 1.5px solid #BBF7D0; border-radius: 14px; padding: 10px 14px;
}
.tf-inbound__globe { color: #16A34A; }
.tf-inbound__src { flex: 1 1 260px; }
.tfslide-enter-active, .tfslide-leave-active { transition: all 0.25s ease; }
.tfslide-enter-from, .tfslide-leave-to { opacity: 0; transform: translateY(-6px); }

/* Inbound submit variant */
.tf-submitbar__btn--in { background: linear-gradient(135deg, #86EFAC, #16A34A); color: #052E16; }
.tf-hist__dir--inb { background: #DCFCE7; color: #15803D; }

.tf-adj { min-width: 360px; max-width: 92vw; border-radius: 14px; }
.min-w-0 { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
