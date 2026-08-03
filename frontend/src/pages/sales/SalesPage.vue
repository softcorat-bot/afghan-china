<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="point_of_sale" controlRoomButton="false" class="q-mt-xs">{{ $t('Sales') }}</m-header>
        </div>

        <!-- Scan a product to narrow the ledger to sales containing it. -->
        <div class="col-12 q-mt-xs">
          <product-scanner mode="search" include-inactive @found="onScanFound" />
        </div>

        <!-- Stat cards -->
        <div class="col-6 col-md-3">
          <stat-card icon="receipt_long" :label="$t('TodaysSales')" :value="Number(summary.count || 0)" color="#16A34A" tint="#DCFCE7" :sub="$t('POS')" sub-icon="storefront" />
        </div>
        <div class="col-6 col-md-3">
          <stat-card icon="payments" :label="$t('TodaysRevenue')" :value="fmt(summary.revenue)" color="#175A8C" tint="#E0EDF7" :sub="summary.base || 'AFN'" sub-icon="account_balance_wallet" />
        </div>
        <div class="col-6 col-md-3">
          <stat-card icon="inventory_2" :label="$t('ItemsSold')" :value="Number(summary.items || 0)" color="#D97706" tint="#FEF3C7" :sub="$t('Products')" sub-icon="category" />
        </div>
        <div class="col-6 col-md-3">
          <stat-card icon="list_alt" :label="$t('TotalSales')" :value="rows.length" color="#7C3AED" tint="#EDE9FE" :sub="$t('CurrentList')" sub-icon="filter_list" />
        </div>

        <!-- Filters -->
        <div class="col-12 col-md-4">
          <q-input outlined dense color="primary" v-model="filters.search" debounce="300" :label="$t('SearchInvoice')" clearable @update:model-value="load">
            <template #prepend><q-icon name="search" color="primary" /></template>
          </q-input>
        </div>
        <div class="col-6 col-md-3">
          <q-select outlined dense color="primary" v-model="filters.status" :options="statusOptions" emit-value map-options :label="$t('Status')" clearable @update:model-value="load">
            <template #prepend><q-icon name="flag" color="primary" /></template>
          </q-select>
        </div>
        <div class="col-6 col-md-2">
          <q-input outlined dense color="primary" type="date" v-model="filters.from" :label="$t('From')" @update:model-value="load">
            <template #prepend><q-icon name="event" color="primary" /></template>
          </q-input>
        </div>
        <div class="col-6 col-md-2">
          <q-input outlined dense color="primary" type="date" v-model="filters.to" :label="$t('To')" @update:model-value="load">
            <template #prepend><q-icon name="event" color="primary" /></template>
          </q-input>
        </div>

        <!-- Sales table -->
        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            no-edit no-delete no-info-dialog info-icon="receipt_long" @info="openReceipt">
            <template v-slot:body-cell-sold_at="props">
              <q-td :props="props">{{ $fmtDateTime(props.row.sold_at) }}</q-td>
            </template>
            <template v-slot:body-cell-customer="props">
              <q-td :props="props">{{ props.row.customer?.name || $t('WalkIn') }}</q-td>
            </template>
            <template v-slot:body-cell-cashier="props">
              <q-td :props="props">{{ props.row.cashier?.name || '—' }}</q-td>
            </template>
            <template v-slot:body-cell-items_count="props">
              <q-td :props="props">
                <q-chip dense size="sm" color="blue-grey-2" text-color="blue-grey-9">{{ props.row.items_count ?? 0 }}</q-chip>
              </q-td>
            </template>
            <template v-slot:body-cell-total="props">
              <q-td :props="props" class="text-weight-bold">{{ fmt(props.row.total) }}</q-td>
            </template>
            <template v-slot:body-cell-status="props">
              <q-td :props="props">
                <q-chip dense size="sm" :color="statusColor(props.row.status)" text-color="white">{{ $t(statusKey(props.row.status)) }}</q-chip>
              </q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>

    <!-- Receipt / detail modal -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 560px">
      <q-card class="bg-white" v-if="sale">
        <n-header icon="receipt_long">{{ $t('Receipt') }} — {{ sale.invoice_no }}</n-header>
        <q-separator />
        <q-card-section class="q-pa-md">
          <div v-if="loadingSale" class="text-center q-py-lg"><q-spinner color="primary" size="2em" /></div>
          <template v-else>
            <!-- Header -->
            <div class="row items-center q-mb-sm">
              <div class="col">
                <div class="text-caption text-grey-6">{{ $t('Customer') }}</div>
                <div class="text-weight-medium">{{ sale.customer?.name || $t('WalkIn') }}</div>
              </div>
              <div class="col text-right">
                <q-chip dense size="sm" :color="statusColor(sale.status)" text-color="white">{{ $t(statusKey(sale.status)) }}</q-chip>
              </div>
            </div>

            <!-- Line items -->
            <q-markup-table flat bordered dense class="my_radio_less">
              <thead class="bg-theme-soft">
                <tr>
                  <th class="text-left">{{ $t('Item') }}</th>
                  <th class="text-center">{{ $t('Qty') }}</th>
                  <th class="text-right">{{ $t('UnitPrice') }}</th>
                  <th class="text-right">{{ $t('LineTotal') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!(sale.items || []).length"><td colspan="4" class="text-center text-grey-5 q-py-md">{{ $t('NoRecordFound') }}</td></tr>
                <tr v-for="(it, i) in sale.items" :key="i">
                  <td class="text-left">
                    {{ it.name }}
                    <div v-if="it.barcode" class="text-caption text-grey-5">{{ it.barcode }}</div>
                  </td>
                  <td class="text-center">
                    {{ it.qty }}
                    <span v-if="it.refunded_qty" class="text-caption text-orange-8"> ({{ $t('Refunded') }}: {{ it.refunded_qty }})</span>
                  </td>
                  <td class="text-right">{{ fmt(it.unit_price) }}</td>
                  <td class="text-right">{{ fmt(it.line_total) }}</td>
                </tr>
              </tbody>
            </q-markup-table>

            <!-- Totals -->
            <div class="q-mt-sm">
              <div class="row"><div class="col text-grey-7">{{ $t('Subtotal') }}</div><div class="col text-right">{{ fmt(sale.subtotal) }}</div></div>
              <div class="row"><div class="col text-grey-7">{{ $t('Discount') }}</div><div class="col text-right">{{ fmt(sale.discount) }}</div></div>
              <div class="row"><div class="col text-grey-7">{{ $t('Tax') }}</div><div class="col text-right">{{ fmt(sale.tax) }}</div></div>
              <q-separator class="q-my-xs" />
              <div class="row text-weight-bold"><div class="col">{{ $t('Total') }}</div><div class="col text-right">{{ fmt(sale.total) }}</div></div>
            </div>

            <!-- Payments -->
            <div class="q-mt-sm" v-if="(sale.payments || []).length">
              <div class="text-caption text-grey-6 q-mb-xs">{{ $t('Payments') }}</div>
              <div v-for="(p, i) in sale.payments" :key="i" class="row">
                <div class="col text-grey-7">{{ p.method }}</div>
                <div class="col text-right">{{ fmt(p.amount) }}</div>
              </div>
              <div class="row"><div class="col text-grey-7">{{ $t('Paid') }}</div><div class="col text-right">{{ fmt(sale.paid) }}</div></div>
              <div class="row"><div class="col text-grey-7">{{ $t('ChangeDue') }}</div><div class="col text-right">{{ fmt(sale.change_due) }}</div></div>
            </div>

            <!-- Footer meta -->
            <q-separator class="q-my-sm" />
            <div class="row text-caption text-grey-6">
              <div class="col">{{ $t('Cashier') }}: {{ sale.cashier?.name || '—' }}</div>
              <div class="col text-right">{{ $fmtDateTime(sale.sold_at) }}</div>
            </div>
          </template>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-px-md q-pb-md">
          <q-btn unelevated color="blue-grey-7" :label="$t('Close')" @click="dialog = false" />
          <q-btn outline color="primary" icon="print" :label="$t('Print')" @click="printReceipt" />
          <q-btn v-if="$can('sale-refund') && sale.status !== 'refunded'" unelevated color="orange-8" icon="undo"
            :label="$t('Refund')" @click="openRefund" />
        </q-card-actions>
      </q-card>
    </m-modal>

    <!-- Per-line refund dialog -->
    <q-dialog v-model="refundDialog">
      <q-card v-if="sale" style="width:560px;max-width:94vw">
        <n-header icon="undo">{{ $t('Refund') }} — {{ sale.invoice_no }}</n-header>
        <q-separator />
        <q-card-section>
          <q-markup-table flat dense>
            <thead><tr><th class="text-left">{{ $t('Item') }}</th><th class="text-center">{{ $t('Sold') }}</th><th class="text-center">{{ $t('Refunded') }}</th><th class="text-center" style="width:120px">{{ $t('RefundQty') }}</th></tr></thead>
            <tbody>
              <tr v-for="line in refundLines" :key="line.id">
                <td class="text-left">{{ line.name }}</td>
                <td class="text-center">{{ line.qty }}</td>
                <td class="text-center text-grey-6">{{ line.already }}</td>
                <td class="text-center">
                  <q-input dense borderless input-class="text-center" type="number" min="0" :max="line.remaining"
                    v-model.number="line.refundQty" :disable="line.remaining <= 0" />
                </td>
              </tr>
            </tbody>
          </q-markup-table>
          <q-input class="q-mt-sm" outlined dense v-model="refundReason" :label="$t('Reason')" />
          <div class="row justify-between q-mt-md text-weight-bold"><span>{{ $t('RefundTotal') }}</span><span class="text-orange-8">{{ fmt(refundTotal) }}</span></div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="orange-8" icon="undo" :label="$t('ProcessRefund')" :loading="refunding" :disable="refundTotal <= 0" @click="submitRefund" />
        </q-card-actions>
      </q-card>
    </q-dialog>
    <receipt-print ref="receiptRef" :sale="sale" :company="companyName" />
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Notify, Dialog } from 'quasar'
import { api } from '@/boot/axios'
import ReceiptPrint from '@/components/ReceiptPrint.vue'

const rows = ref([])
const loading = ref(false)
const filter = ref('')
const summary = reactive({ count: 0, revenue: 0, items: 0, base: 'AFN' })

const dialog = ref(false)
const sale = ref(null)
const loadingSale = ref(false)
const refunding = ref(false)

const filters = reactive({ search: '', status: null, from: '', to: '' })

const statusOptions = [
  { label: 'Completed', value: 'completed' },
  { label: 'Refunded', value: 'refunded' },
  { label: 'Void', value: 'void' },
]

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'invoice_no', label: 'Invoice', field: 'invoice_no', align: 'left', sortable: true },
  { name: 'sold_at', label: 'DateTime', field: 'sold_at', align: 'left', sortable: true },
  { name: 'customer', label: 'Customer', field: row => row.customer?.name, align: 'left' },
  { name: 'cashier', label: 'Cashier', field: row => row.cashier?.name, align: 'left' },
  { name: 'items_count', label: 'Items', field: 'items_count', align: 'center' },
  { name: 'total', label: 'Total', field: 'total', align: 'right', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'center' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' },
]

function fmt (v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' AFN' }
function statusColor (s) { return s === 'completed' ? 'green' : s === 'refunded' ? 'orange' : s === 'partially_refunded' ? 'amber-8' : 'grey' }
function statusKey (s) { return s === 'completed' ? 'Completed' : s === 'refunded' ? 'Refunded' : s === 'partially_refunded' ? 'PartiallyRefunded' : 'Void' }

async function load () {
  loading.value = true
  try {
    const params = {}
    if (filters.search) params.search = filters.search
    if (filters.status) params.status = filters.status
    if (filters.from) params.from = filters.from
    if (filters.to) params.to = filters.to
    const { data } = await api.get('/sales', { params })
    rows.value = data || []
  } finally { loading.value = false }
}

async function loadSummary () {
  try {
    const { data } = await api.get('/sales/summary')
    Object.assign(summary, data || {})
  } catch (_) {}
}

async function openReceipt (id) {
  dialog.value = true
  loadingSale.value = true
  sale.value = rows.value.find(r => r.id === id) || { id, invoice_no: '' }
  try {
    const { data } = await api.get('/sales/' + id)
    sale.value = data
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed to load' })
  } finally { loadingSale.value = false }
}

const refundDialog = ref(false)
const refundReason = ref('')
const refundLines = ref([])

function openRefund () {
  if (!sale.value) return
  refundReason.value = ''
  refundLines.value = (sale.value.items || []).map(it => {
    const already = Number(it.refunded_qty) || 0
    const remaining = Math.max(0, Number(it.qty) - already)
    return {
      id: it.id, name: it.name, qty: Number(it.qty), already, remaining,
      perUnit: Number(it.qty) > 0 ? Number(it.line_total) / Number(it.qty) : 0,
      refundQty: remaining,
    }
  })
  refundDialog.value = true
}

const refundTotal = computed(() => refundLines.value.reduce((s, l) => {
  const q = Math.min(Math.max(0, Number(l.refundQty) || 0), l.remaining)
  return s + q * l.perUnit
}, 0))

async function submitRefund () {
  const items = refundLines.value
    .filter(l => Number(l.refundQty) > 0 && l.remaining > 0)
    .map(l => ({ sale_item_id: l.id, qty: Math.min(Number(l.refundQty), l.remaining) }))
  if (!items.length) return
  refunding.value = true
  try {
    const { data } = await api.post('/sales/' + sale.value.id + '/refund', { reason: refundReason.value || null, items })
    sale.value = { ...sale.value, ...data }
    refundDialog.value = false
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Refunded' })
    load(); loadSummary()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Refund failed' })
  } finally { refunding.value = false }
}

const receiptRef = ref(null)
const companyName = ref('Afghan China Shopping Center')
api.get('/user').then(({ data }) => { companyName.value = data?.company?.name_en || companyName.value }).catch(() => {})
function printReceipt () { receiptRef.value?.print() }

/** Scanning a product searches the sales ledger for it by name. */
function onScanFound (p) {
  filters.search = p.name
  load()
}

onMounted(() => { load(); loadSummary() })
</script>
