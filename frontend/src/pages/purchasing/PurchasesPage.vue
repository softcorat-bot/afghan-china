<template>
  <q-page class="q-pa-md">
    <m-backgrounds>
      <m-header icon="local_shipping">{{ $t('Purchases') }}</m-header>

      <!-- Receiving goods: scan each carton straight onto the document.
           Draft and archived products are included — you can receive stock
           for something that is not on sale yet. -->
      <div class="q-mb-sm">
        <product-scanner mode="search" include-inactive :notify="false" @found="onScanFound" />
      </div>

      <!-- stats -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-6 col-md-3"><stat-card icon="receipt_long" :label="$t('TotalPurchases')" :value="rows.length" color="#175A8C" tint="#E0EDF7" /></div>
        <div class="col-6 col-md-3"><stat-card icon="pending_actions" :label="$t('DraftPurchases')" :value="draftCount" color="#D97706" tint="#FEF3C7" /></div>
        <div class="col-6 col-md-3"><stat-card icon="inventory" :label="$t('ReceivedPurchases')" :value="receivedCount" color="#16A34A" tint="#DCFCE7" /></div>
        <div class="col-6 col-md-3"><stat-card icon="payments" :label="$t('PurchaseValue')" :value="fmt(totalValue)" color="#7C3AED" tint="#EDE9FE" /></div>
      </div>

      <!-- filters + add -->
      <div class="row q-col-gutter-sm q-mb-sm items-center">
        <div class="col-12 col-sm">
          <q-input outlined dense v-model="search" :label="$t('Search')" debounce="300" @update:model-value="load">
            <template #prepend><q-icon name="search" /></template>
          </q-input>
        </div>
        <div class="col-6 col-sm-3">
          <q-select outlined dense v-model="statusFilter" :options="statusOptions" emit-value map-options clearable :label="$t('Status')" @update:model-value="load" />
        </div>
        <div class="col-6 col-sm-auto">
          <q-btn unelevated no-caps color="primary" icon="add" :label="$t('NewPurchase')" @click="openNew" />
        </div>
      </div>

      <n-table :data="rows" :columns="columns" row-key="id" :loading="loading" v-model:filter="filter">
        <template v-slot:body-cell-status="props">
          <q-td :props="props">
            <q-chip dense size="sm" :color="statusColor(props.row.status)" text-color="white">{{ $t(statusLabel(props.row.status)) }}</q-chip>
          </q-td>
        </template>
        <template v-slot:body-cell-total="props">
          <q-td :props="props" class="text-weight-bold">{{ fmt(props.row.total) }}</q-td>
        </template>
        <template v-slot:body-cell-ops="props">
          <q-td :props="props" class="text-right">
            <q-btn round flat dense color="info" icon="visibility" @click="openView(props.row)"><q-tooltip>{{ $t('View') }}</q-tooltip></q-btn>
            <q-btn v-if="props.row.status === 'draft'" round flat dense color="positive" icon="inventory" @click="receive(props.row)"><q-tooltip>{{ $t('Receive') }}</q-tooltip></q-btn>
            <q-btn v-if="props.row.status === 'draft'" round flat dense color="negative" icon="delete" @click="remove(props.row)"><q-tooltip>{{ $t('Delete') }}</q-tooltip></q-btn>
          </q-td>
        </template>
      </n-table>
    </m-backgrounds>

    <!-- ── NEW PURCHASE modal ─────────────────────────────── -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 820px; max-width: 96vw">
      <q-card class="bg-white">
        <n-header icon="local_shipping">{{ $t('NewPurchase') }}</n-header>
        <q-separator />
        <q-card-section class="row q-col-gutter-sm">
          <div class="col-12 col-sm-5">
            <q-select outlined dense options-dense v-model="form.supplier_id" :options="supplierOpts" emit-value map-options clearable :label="$t('Supplier')">
              <template #prepend><q-icon name="storefront" color="primary" /></template>
            </q-select>
          </div>
          <div class="col-12 col-sm-4"><q-input outlined dense v-model="form.supplier_invoice" :label="$t('SupplierInvoice')" /></div>
          <div class="col-12 col-sm-3"><q-input outlined dense type="date" v-model="form.purchased_at" :label="$t('Date')" /></div>
        </q-card-section>

        <!-- product picker -->
        <q-card-section class="q-pt-none">
          <q-select outlined dense use-input options-dense input-debounce="200" v-model="picker"
            :options="productOpts" @filter="filterProducts" :label="$t('AddProduct')" @update:model-value="addLine">
            <template #prepend><q-icon name="add_shopping_cart" color="primary" /></template>
          </q-select>
        </q-card-section>

        <!-- lines -->
        <q-card-section class="q-pt-none">
          <div v-if="form.items.length === 0" class="text-grey-5 text-center q-pa-md">{{ $t('NoLinesYet') }}</div>
          <q-markup-table v-else flat dense class="pur-lines">
            <thead>
              <tr>
                <th class="text-left">{{ $t('Product') }}</th>
                <th class="text-right" style="width:120px">{{ $t('CostPrice') }}</th>
                <th class="text-right" style="width:100px">{{ $t('Qty') }}</th>
                <th class="text-right" style="width:120px">{{ $t('LineTotal') }}</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(l, i) in form.items" :key="l.product_id">
                <td class="text-left">{{ l.name }}</td>
                <td><q-input dense borderless input-class="text-right" type="number" min="0" v-model.number="l.cost_price" /></td>
                <td><q-input dense borderless input-class="text-right" type="number" min="0" v-model.number="l.qty" /></td>
                <td class="text-right text-weight-bold">{{ fmt(l.cost_price * l.qty) }}</td>
                <td><q-btn round flat dense size="sm" color="negative" icon="close" @click="form.items.splice(i, 1)" /></td>
              </tr>
            </tbody>
          </q-markup-table>
        </q-card-section>

        <q-separator />
        <q-card-section class="row items-center">
          <div class="col">
            <q-input outlined dense type="number" min="0" style="max-width:180px" v-model.number="form.paid" :label="$t('PaidNow')" suffix="AFN" />
          </div>
          <div class="col text-right">
            <div class="text-grey-7">{{ $t('Subtotal') }}: <b>{{ fmt(subtotal) }}</b></div>
            <div class="text-h6">{{ $t('Total') }}: {{ fmt(subtotal) }}</div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="primary" icon="save" :label="$t('SaveDraft')" :loading="saving" :disable="form.items.length === 0" @click="save" />
        </q-card-actions>
      </q-card>
    </m-modal>

    <!-- ── VIEW modal ─────────────────────────────────────── -->
    <m-modal :showCM="viewDialog" @update:showCM="viewDialog = $event" card_style="width: 640px; max-width: 94vw">
      <q-card v-if="current" class="bg-white">
        <n-header icon="receipt_long">{{ current.reference }}</n-header>
        <q-separator />
        <q-card-section>
          <div class="row justify-between q-mb-xs"><span class="text-grey-7">{{ $t('Supplier') }}</span><b>{{ current.supplier?.name || '—' }}</b></div>
          <div class="row justify-between q-mb-xs"><span class="text-grey-7">{{ $t('Status') }}</span><q-chip dense size="sm" :color="statusColor(current.status)" text-color="white">{{ $t(statusLabel(current.status)) }}</q-chip></div>
          <q-separator class="q-my-sm" />
          <q-markup-table flat dense>
            <thead><tr><th class="text-left">{{ $t('Product') }}</th><th class="text-right">{{ $t('Qty') }}</th><th class="text-right">{{ $t('CostPrice') }}</th><th class="text-right">{{ $t('LineTotal') }}</th></tr></thead>
            <tbody>
              <tr v-for="it in current.items" :key="it.id"><td class="text-left">{{ it.name }}</td><td class="text-right">{{ it.qty }}</td><td class="text-right">{{ fmt(it.cost_price) }}</td><td class="text-right">{{ fmt(it.line_total) }}</td></tr>
            </tbody>
          </q-markup-table>
          <q-separator class="q-my-sm" />
          <div class="row justify-between"><span>{{ $t('Total') }}</span><b>{{ fmt(current.total) }}</b></div>
          <div class="row justify-between text-grey-7"><span>{{ $t('Paid') }}</span><span>{{ fmt(current.paid) }}</span></div>
          <div v-if="(current.returns || []).length" class="q-mt-sm">
            <div class="text-caption text-grey-6">{{ $t('Returns') }}</div>
            <div v-for="r in current.returns" :key="r.id" class="row justify-between text-negative"><span>{{ r.reference }}</span><span>- {{ fmt(r.amount) }}</span></div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Close')" v-close-popup />
          <q-btn v-if="current.status === 'received'" outline no-caps color="negative" icon="assignment_return" :label="$t('ReturnGoods')" @click="openReturn" />
          <q-btn v-if="current.status === 'draft'" unelevated no-caps color="positive" icon="inventory" :label="$t('Receive')" @click="receive(current, true)" />
        </q-card-actions>
      </q-card>
    </m-modal>

    <!-- Return goods dialog -->
    <m-modal :showCM="returnDialog" @update:showCM="returnDialog = $event" card_style="width: 560px; max-width: 94vw">
      <q-card v-if="current" class="bg-white">
        <n-header icon="assignment_return">{{ $t('ReturnGoods') }} — {{ current.reference }}</n-header>
        <q-separator />
        <q-card-section>
          <q-markup-table flat dense>
            <thead><tr><th class="text-left">{{ $t('Product') }}</th><th class="text-center">{{ $t('Received') }}</th><th class="text-center">{{ $t('Returned') }}</th><th class="text-center" style="width:110px">{{ $t('ReturnQty') }}</th></tr></thead>
            <tbody>
              <tr v-for="l in returnLines" :key="l.id">
                <td class="text-left">{{ l.name }}</td>
                <td class="text-center">{{ l.qty }}</td>
                <td class="text-center text-grey-6">{{ l.already }}</td>
                <td class="text-center"><q-input dense borderless input-class="text-center" type="number" min="0" :max="l.remaining" v-model.number="l.returnQty" :disable="l.remaining <= 0" /></td>
              </tr>
            </tbody>
          </q-markup-table>
          <q-input class="q-mt-sm" outlined dense v-model="returnReason" :label="$t('Reason')" />
          <div class="row justify-between q-mt-md text-weight-bold"><span>{{ $t('ReturnValue') }}</span><span class="text-negative">{{ fmt(returnValue) }}</span></div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="negative" icon="assignment_return" :label="$t('ProcessReturn')" :loading="returning" :disable="returnValue <= 0" @click="submitReturn" />
        </q-card-actions>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { useQuasar } from 'quasar'
import { api } from '@/boot/axios'

const $q = useQuasar()
const { proxy } = getCurrentInstance()
const t = (k) => (proxy?.$t ? proxy.$t(k) : k)
const fmt = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' AFN'

const rows = ref([])
const suppliers = ref([])
const products = ref([])
const loading = ref(false)
const saving = ref(false)
const search = ref('')
const filter = ref('')
const statusFilter = ref(null)

const statusOptions = [
  { label: t('Draft'), value: 'draft' },
  { label: t('Received'), value: 'received' },
  { label: t('Cancelled'), value: 'cancelled' },
]

const columns = [
  { name: 'reference', label: t('Reference'), field: 'reference', align: 'left', sortable: true },
  { name: 'supplier', label: t('Supplier'), field: r => r.supplier?.name || '—', align: 'left' },
  { name: 'purchased_at', label: t('Date'), field: 'purchased_at', align: 'left', sortable: true },
  { name: 'items_count', label: t('Items'), field: 'items_count', align: 'center' },
  { name: 'total', label: t('Total'), field: 'total', align: 'right', sortable: true },
  { name: 'status', label: t('Status'), field: 'status', align: 'center' },
  { name: 'ops', label: t('Actions'), field: 'ops', align: 'right' },
]

const draftCount = computed(() => rows.value.filter(r => r.status === 'draft').length)
const receivedCount = computed(() => rows.value.filter(r => r.status === 'received').length)
const totalValue = computed(() => rows.value.reduce((s, r) => s + Number(r.total || 0), 0))

function statusColor (s) { return { draft: 'orange', received: 'positive', cancelled: 'grey' }[s] || 'grey' }
function statusLabel (s) { return { draft: 'Draft', received: 'Received', cancelled: 'Cancelled' }[s] || s }

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/purchases', { params: { search: search.value || undefined, status: statusFilter.value || undefined } })
    rows.value = data || []
  } catch (_) {} finally { loading.value = false }
}
async function loadRefs () {
  try { const { data } = await api.get('/suppliers'); suppliers.value = data || [] } catch (_) {}
  try { const { data } = await api.get('/products'); products.value = data || [] } catch (_) {}
}
/**
 * Receiving: a scan puts the carton straight onto the document being built,
 * bumping the quantity when the same product is scanned twice — which is what
 * happens when a pallet of one item is counted in.
 */
function onScanFound (p) {
  if (!dialog.value) openNew()
  const line = form.items.find(l => l.product_id === p.id)
  if (line) {
    line.qty = Number(line.qty || 0) + 1
  } else {
    form.items.push({ product_id: p.id, name: p.name, cost_price: Number(p.cost_price) || 0, qty: 1 })
  }
}

onMounted(() => { load(); loadRefs() })

const supplierOpts = computed(() => suppliers.value.map(s => ({ label: s.phone ? `${s.name} · ${s.phone}` : s.name, value: s.id })))

// product picker
const picker = ref(null)
const productFilter = ref('')
const productOpts = computed(() => products.value
  .filter(p => !productFilter.value || (p.name + ' ' + (p.barcode || '') + ' ' + (p.sku || '')).toLowerCase().includes(productFilter.value))
  .map(p => ({ label: `${p.name}${p.barcode ? ' · ' + p.barcode : ''}`, value: p.id, raw: p })))
function filterProducts (val, update) { update(() => { productFilter.value = (val || '').toLowerCase() }) }

// modal state
const dialog = ref(false)
const form = reactive({ supplier_id: null, supplier_invoice: '', purchased_at: new Date().toISOString().slice(0, 10), paid: 0, items: [] })

function openNew () {
  form.supplier_id = null; form.supplier_invoice = ''; form.purchased_at = new Date().toISOString().slice(0, 10); form.paid = 0; form.items = []
  dialog.value = true
}
function addLine (opt) {
  if (!opt) return
  const p = opt.raw
  if (!form.items.find(l => l.product_id === p.id)) {
    form.items.push({ product_id: p.id, name: p.name, cost_price: Number(p.cost_price) || 0, qty: 1 })
  }
  picker.value = null
}
const subtotal = computed(() => form.items.reduce((s, l) => s + (Number(l.cost_price) || 0) * (Number(l.qty) || 0), 0))

async function save () {
  if (!form.items.length) return
  saving.value = true
  try {
    await api.post('/purchases', {
      supplier_id: form.supplier_id || null,
      supplier_invoice: form.supplier_invoice || null,
      purchased_at: form.purchased_at || null,
      paid: Number(form.paid) || 0,
      items: form.items.map(l => ({ product_id: l.product_id, qty: Number(l.qty), cost_price: Number(l.cost_price) })),
    })
    $q.notify({ message: t('Saved'), color: 'positive', position: 'top' })
    dialog.value = false
    load()
  } catch (e) {
    $q.notify({ message: e?.response?.data?.message || t('SaveFailed'), color: 'negative', position: 'top' })
  } finally { saving.value = false }
}

// view + receive
const viewDialog = ref(false)
const current = ref(null)

// returns
const returnDialog = ref(false)
const returning = ref(false)
const returnReason = ref('')
const returnLines = ref([])
function openReturn () {
  returnReason.value = ''
  returnLines.value = (current.value.items || []).map(it => {
    const already = Number(it.returned_qty) || 0
    const remaining = Math.max(0, Number(it.qty) - already)
    return { id: it.id, name: it.name, qty: Number(it.qty), already, remaining, cost: Number(it.cost_price), returnQty: 0 }
  })
  returnDialog.value = true
}
const returnValue = computed(() => returnLines.value.reduce((s, l) => s + Math.min(Math.max(0, Number(l.returnQty) || 0), l.remaining) * l.cost, 0))
async function submitReturn () {
  const items = returnLines.value.filter(l => Number(l.returnQty) > 0 && l.remaining > 0).map(l => ({ purchase_item_id: l.id, qty: Math.min(Number(l.returnQty), l.remaining) }))
  if (!items.length) return
  returning.value = true
  try {
    const { data } = await api.post(`/purchases/${current.value.id}/return`, { reason: returnReason.value || null, items })
    current.value = data
    returnDialog.value = false
    $q.notify({ message: t('GoodsReturned'), color: 'positive', position: 'top' })
    load(); loadRefs()
  } catch (e) { $q.notify({ message: e?.response?.data?.message || t('SaveFailed'), color: 'negative', position: 'top' }) } finally { returning.value = false }
}
async function openView (row) {
  try { const { data } = await api.get(`/purchases/${row.id}`); current.value = data; viewDialog.value = true } catch (_) {}
}
function receive (row, fromView = false) {
  $q.dialog({ title: t('Receive'), message: t('ReceiveConfirm'), cancel: true }).onOk(async () => {
    try {
      await api.post(`/purchases/${row.id}/receive`)
      $q.notify({ message: t('StockReceived'), color: 'positive', position: 'top' })
      if (fromView) viewDialog.value = false
      load(); loadRefs()
    } catch (e) {
      $q.notify({ message: e?.response?.data?.message || t('SaveFailed'), color: 'negative', position: 'top' })
    }
  })
}
function remove (row) {
  $q.dialog({ title: t('Delete'), message: t('DeleteConfirm'), cancel: true }).onOk(async () => {
    try { await api.delete(`/purchases/${row.id}`); $q.notify({ message: t('Deleted'), color: 'positive', position: 'top' }); load() } catch (e) { $q.notify({ message: e?.response?.data?.message || t('SaveFailed'), color: 'negative', position: 'top' }) }
  })
}
</script>

<style scoped>
.pur-lines th { font-weight: 700; color: #475569; }
</style>
