<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="medication_liquid" controlRoomButton="false" class="q-mt-xs">{{ $t('ExpiryTracking') }}</m-header>
        </div>

        <!-- Scan the carton in your hand to see its batches and expiry dates. -->
        <div class="col-12 q-mt-xs">
          <product-scanner mode="search" include-inactive @found="onScanFound" />
        </div>

        <!-- Summary stat cards -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-md">
            <div class="col-6 col-sm-3"><stat-card dense icon="inventory" :label="$t('TotalBatches')" :value="summary.total_batches ?? 0" color="#175A8C" tint="#E0EDF7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="schedule" :label="$t('ExpiringSoon')" :value="summary.expiring_soon ?? 0" color="#D97706" tint="#FEF3C7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="event_busy" :label="$t('Expired')" :value="summary.expired_batches ?? 0" color="#DC2626" tint="#FEE2E2" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="savings" :label="$t('AvailableValue')" :value="fmt(summary.available_value)" color="#16A34A" tint="#DCFCE7" /></div>
          </div>
        </div>

        <!-- Unacknowledged alerts strip -->
        <div v-if="alerts.length" class="col-12 q-mt-sm">
          <div class="exp-alerts my_radio_less">
            <div class="exp-alerts__head">
              <q-icon name="notification_important" size="17px" />
              <b>{{ $t('LowStockAlerts') }}</b>
              <q-space />
              <q-btn dense flat no-caps size="sm" color="white" :label="$t('MarkAllRead')" @click="acknowledgeAll" />
            </div>
            <div class="exp-alerts__row">
              <div v-for="a in alerts" :key="a.id" class="exp-alert" :class="'exp-alert--' + a.alert_type">
                <q-icon :name="alertIcon(a.alert_type)" size="16px" />
                <div class="min-w-0">
                  <div class="exp-alert__name">{{ a.batch?.product?.name || '—' }}</div>
                  <div class="exp-alert__meta">{{ a.batch?.batch_number }} · {{ $t(alertKey(a.alert_type)) }} · {{ (a.batch?.expiry_date || '').slice(0, 10) }}</div>
                </div>
                <q-btn dense flat round size="sm" icon="done" color="grey-7" @click="acknowledge(a)"><q-tooltip>{{ $t('Done') }}</q-tooltip></q-btn>
              </div>
            </div>
          </div>
        </div>

        <!-- Filters -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-sm items-center q-pa-sm bg-blue-grey-1 my_radio_less" style="border-radius:10px">
            <div class="col-6 col-sm-3">
              <q-select outlined dense color="primary" bg-color="white" v-model="filters.status" :options="statusOptions" :label="$t('Status')" emit-value map-options clearable @update:model-value="loadBatches">
                <template #prepend><q-icon name="flag" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-sm-3">
              <q-select outlined dense color="primary" bg-color="white" v-model="filters.product_id" :options="productOptions" :label="$t('Product')" emit-value map-options clearable use-input input-debounce="0" @filter="filterProducts" @update:model-value="loadBatches">
                <template #prepend><q-icon name="inventory_2" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-sm-3">
              <q-select outlined dense color="primary" bg-color="white" v-model="filters.expiring" :options="expiringOptions" :label="$t('ExpiringSoon')" emit-value map-options clearable @update:model-value="loadBatches">
                <template #prepend><q-icon name="schedule" color="primary" /></template>
              </q-select>
            </div>
          </div>
        </div>

        <action-bar :rows="batches" :columns="columns" filename="expiry-batches"
          create-perm="purchase-create" :add-label="$t('AddBatch')" @add="openCreate" @update:filtered="filteredRows = $event" />

        <div class="col-12">
          <n-table :loading="loading" :data="batches" :columns="columns" v-model:filter="filter"
            :noEdit="true" :noDelete="true" :noInfo="true">

            <template v-slot:body-cell-product="props">
              <q-td :props="props">
                <div class="text-weight-medium prod-link" @click.stop="props.row.product && $router.push('/products/' + props.row.product.id)">{{ props.row.product?.name || '—' }}</div>
                <div class="text-caption text-grey-6">{{ props.row.product?.category?.name || '—' }}</div>
              </q-td>
            </template>

            <template v-slot:body-cell-batch_number="props">
              <q-td :props="props">
                <div class="text-weight-bold">{{ props.row.batch_number }}</div>
                <div class="text-caption text-grey-6">{{ (props.row.manufacture_date || '').slice(0, 10) }}</div>
              </q-td>
            </template>

            <template v-slot:body-cell-expiry_date="props">
              <q-td :props="props">
                <q-badge :color="expiryColor(props.row)">{{ (props.row.expiry_date || '').slice(0, 10) }}</q-badge>
                <div class="text-caption text-grey-6 q-mt-xs">{{ daysLeftLabel(props.row) }}</div>
              </q-td>
            </template>

            <template v-slot:body-cell-quantity="props">
              <q-td :props="props">
                <b>{{ num(props.row.quantity_available) }}</b><span class="text-grey-6"> / {{ num(props.row.quantity_received) }}</span>
                <div class="exp-qty-track"><div class="exp-qty-bar" :style="`width:${qtyPct(props.row)}%`"></div></div>
              </q-td>
            </template>

            <template v-slot:body-cell-unit_cost="props">
              <q-td :props="props">{{ fmt(props.row.unit_cost) }}</q-td>
            </template>

            <template v-slot:body-cell-status="props">
              <q-td :props="props">
                <q-chip dense size="sm" :color="statusColor(props.row.status)" text-color="white">{{ $t(statusKey(props.row.status)) }}</q-chip>
              </q-td>
            </template>

            <template v-slot:body-cell-batch_actions="props">
              <q-td :props="props" class="text-right">
                <q-btn size="sm" dense color="deep-orange" icon="delete_sweep" class="q-ml-xs"
                  :disable="props.row.status === 'discarded' || Number(props.row.quantity_available) <= 0"
                  @click="openDiscard(props.row)">
                  <q-tooltip>{{ $t('Discard') }}</q-tooltip>
                </q-btn>
              </q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>

    <!-- ── Add batch ── -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 560px">
      <q-card class="bg-white">
        <n-header icon="medication_liquid">{{ $t('AddBatch') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12">
              <q-select outlined dense color="primary" class="q-mt-sm" v-model="form.product_id" :options="productOptions" :label="$t('Product')" emit-value map-options use-input input-debounce="0" @filter="filterProducts"
                :rules="[v => !!v || $t('FieldIsRequired')]">
                <template #prepend><q-icon name="inventory_2" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-12 col-sm-6">
              <q-input outlined dense color="primary" class="q-mt-sm" v-model="form.batch_number" :label="$t('BatchNumber')" :rules="[v => !!v || $t('FieldIsRequired')]">
                <template #prepend><q-icon name="tag" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense color="primary" class="q-mt-sm" type="date" v-model="form.manufacture_date" :label="$t('ManufactureDate')" :rules="[v => !!v || $t('FieldIsRequired')]" />
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense color="primary" class="q-mt-sm" type="date" v-model="form.expiry_date" :label="$t('ExpiryDate')" :rules="[v => !!v || $t('FieldIsRequired')]" />
            </div>
            <div class="col-6 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" min="0.01" v-model.number="form.quantity_received" :label="$t('Qty')" :rules="[v => Number(v) > 0 || $t('FieldIsRequired')]">
                <template #prepend><q-icon name="numbers" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" min="0" v-model.number="form.unit_cost" :label="$t('UnitCost')" suffix="AFN" :rules="[v => (v !== null && v !== '') || $t('FieldIsRequired')]">
                <template #prepend><q-icon name="payments" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12">
              <q-input outlined dense color="primary" class="q-mt-sm" type="textarea" autogrow v-model="form.notes" :label="$t('Notes')">
                <template #prepend><q-icon name="notes" color="primary" /></template>
              </q-input>
            </div>
          </q-card-section>
          <q-separator />
          <n-submit :submitting="saving" :label="$t('Save')" />
        </q-form>
      </q-card>
    </m-modal>

    <!-- ── Discard from batch ── -->
    <m-modal :showCM="discardDlg" @update:showCM="discardDlg = $event" card_style="width: 460px">
      <q-card class="bg-white">
        <n-header icon="delete_sweep">{{ $t('Discard') }} — {{ discardBatch?.batch_number }}</n-header>
        <q-separator />
        <q-form @submit="doDiscard">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 text-caption text-grey-7">
              {{ discardBatch?.product?.name }} · {{ $t('Available') }}: <b>{{ num(discardBatch?.quantity_available) }}</b>
            </div>
            <div class="col-6">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" min="0.01" :max="discardBatch?.quantity_available" v-model.number="discardForm.quantity" :label="$t('Qty')"
                :rules="[v => (Number(v) > 0 && Number(v) <= Number(discardBatch?.quantity_available || 0)) || $t('FieldIsRequired')]">
                <template #prepend><q-icon name="numbers" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-6">
              <q-input outlined dense color="primary" class="q-mt-sm" v-model="discardForm.reason" :label="$t('Reason')">
                <template #prepend><q-icon name="help_outline" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12">
              <q-input outlined dense color="primary" class="q-mt-sm" type="textarea" autogrow v-model="discardForm.notes" :label="$t('Notes')" />
            </div>
          </q-card-section>
          <q-separator />
          <n-submit :submitting="discarding" :label="$t('Discard')" />
        </q-form>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, reactive, getCurrentInstance, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()

const summary = ref({})
const alerts = ref([])
const batches = ref([])
const filteredRows = ref([])
const loading = ref(false)
const filter = ref('')
const filters = reactive({ status: null, product_id: null, expiring: null })

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Expired', value: 'expired' },
  { label: 'Discarded', value: 'discarded' },
]
const expiringOptions = [
  { label: '7', value: 7 },
  { label: '14', value: 14 },
  { label: '30', value: 30 },
  { label: '90', value: 90 },
]

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'product', label: 'Product', field: row => row.product?.name, align: 'left', sortable: true },
  { name: 'batch_number', label: 'BatchNumber', field: 'batch_number', align: 'left', sortable: true },
  { name: 'expiry_date', label: 'ExpiryDate', field: 'expiry_date', align: 'left', sortable: true },
  { name: 'quantity', label: 'Available', field: 'quantity_available', align: 'left', sortable: true },
  { name: 'unit_cost', label: 'UnitCost', field: 'unit_cost', align: 'left', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
  { name: 'batch_actions', label: 'Actions', field: 'id', align: 'right' },
]

function fmt (v) { return (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' AFN' }
function num (v) { return Number(v || 0) }
function statusColor (s) { return s === 'active' ? 'green-7' : s === 'expired' ? 'red-6' : 'grey-6' }
function statusKey (s) { return s === 'active' ? 'Active' : s === 'expired' ? 'Expired' : 'Discarded' }
function alertIcon (t) { return t === 'expired' ? 'event_busy' : t === 'expiring_soon' ? 'schedule' : 'trending_down' }
function alertKey (t) { return t === 'expired' ? 'Expired' : t === 'expiring_soon' ? 'ExpiringSoon' : 'LowStock' }

function daysLeft (row) {
  const d = new Date((row.expiry_date || '').slice(0, 10) + 'T00:00:00')
  if (isNaN(d)) return null
  return Math.ceil((d - new Date()) / 864e5)
}
function daysLeftLabel (row) {
  const n = daysLeft(row)
  if (n === null) return ''
  return n < 0 ? proxy.$t('Expired') : n + ' ' + proxy.$t('Day')
}
function expiryColor (row) {
  const n = daysLeft(row)
  if (n === null) return 'grey-6'
  return n < 0 ? 'red-6' : n <= 14 ? 'orange-8' : 'green-7'
}
function qtyPct (row) {
  const r = Number(row.quantity_received) || 0
  return r > 0 ? Math.round((Number(row.quantity_available) || 0) / r * 100) : 0
}

// ── data ──
async function loadSummary () {
  try { const { data } = await api.get('/inventory/expiry-summary'); summary.value = data } catch (_) {}
}
async function loadAlerts () {
  try { const { data } = await api.get('/expiry-alerts'); alerts.value = (data?.data || data || []).filter(a => !a.acknowledged) } catch (_) { alerts.value = [] }
}
async function loadBatches () {
  loading.value = true
  try {
    const params = { per_page: 1000 }
    for (const k of ['status', 'product_id', 'expiring']) if (filters[k]) params[k] = filters[k]
    const { data } = await api.get('/inventory/batches', { params })
    batches.value = data?.data || data || []
  } finally { loading.value = false }
}

async function acknowledge (a) {
  try { await api.post(`/expiry-alerts/${a.id}/acknowledge`); alerts.value = alerts.value.filter(x => x.id !== a.id); loadSummary() } catch (_) {}
}
async function acknowledgeAll () {
  try { await api.post('/expiry-alerts/acknowledge-all'); alerts.value = []; loadSummary() } catch (_) {}
}

// ── add batch ──
const dialog = ref(false)
const saving = ref(false)
const blank = () => ({ product_id: null, batch_number: '', manufacture_date: new Date().toISOString().slice(0, 10), expiry_date: '', quantity_received: null, unit_cost: null, notes: '' })
const form = reactive(blank())

function openCreate () { Object.assign(form, blank()); dialog.value = true }
async function save () {
  saving.value = true
  try {
    await api.post('/inventory/batches', { ...form })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: proxy.$t('Saved') })
    dialog.value = false
    loadBatches(); loadSummary(); loadAlerts()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

// ── discard ──
const discardDlg = ref(false)
const discarding = ref(false)
const discardBatch = ref(null)
const discardForm = reactive({ quantity: null, reason: '', notes: '' })

function openDiscard (row) {
  discardBatch.value = row
  Object.assign(discardForm, { quantity: null, reason: '', notes: '' })
  discardDlg.value = true
}
async function doDiscard () {
  discarding.value = true
  try {
    await api.put(`/inventory/batches/${discardBatch.value.id}/discard`, { ...discardForm })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: proxy.$t('Saved') })
    discardDlg.value = false
    loadBatches(); loadSummary()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.error || e?.response?.data?.message || 'Failed' })
  } finally { discarding.value = false }
}

// ── product options ──
const allProductOptions = ref([])
const productOptions = ref([])
function filterProducts (val, update) {
  update(() => {
    const needle = (val || '').toLowerCase()
    productOptions.value = needle
      ? allProductOptions.value.filter(o => o.label.toLowerCase().includes(needle))
      : allProductOptions.value
  })
}
async function loadMeta () {
  try {
    const { data } = await api.get('/expiry-products')
    const list = data?.data || data || []
    allProductOptions.value = list.map(p => ({ label: p.name, value: p.id }))
  } catch (_) {}
  if (!allProductOptions.value.length) {
    try {
      const { data } = await api.get('/products')
      allProductOptions.value = (data || []).map(p => ({ label: p.name, value: p.id }))
    } catch (_) {}
  }
  productOptions.value = allProductOptions.value
}

/** Scanning a product filters the batch list to that product. */
function onScanFound (p) {
  filters.product_id = p.id
  loadBatches()
}

onMounted(() => { loadSummary(); loadAlerts(); loadBatches(); loadMeta() })
</script>

<style scoped>
.exp-alerts {
  border-radius: 12px; overflow: hidden;
  border: 1px solid #FDE68A; background: #FFFBEB;
}
.exp-alerts__head {
  display: flex; align-items: center; gap: 8px;
  background: #D97706; color: #fff; padding: 7px 12px; font-size: 12.5px;
}
.exp-alerts__row { display: flex; gap: 10px; padding: 10px 12px; overflow-x: auto; }
.exp-alert {
  display: flex; align-items: center; gap: 8px; flex-shrink: 0;
  background: #fff; border: 1px solid #FDE68A; border-radius: 10px;
  padding: 7px 10px; min-width: 220px;
}
.exp-alert--expired { border-color: #FCA5A5; }
.exp-alert--expired .q-icon:first-child { color: #DC2626; }
.exp-alert--expiring_soon .q-icon:first-child { color: #D97706; }
.exp-alert__name { font-size: 12.5px; font-weight: 700; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.exp-alert__meta { font-size: 10.5px; color: #94A3B8; }

.exp-qty-track { height: 5px; width: 84px; border-radius: 4px; background: #F1F5F9; overflow: hidden; margin-top: 4px; }
.exp-qty-bar { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #2E7CC4, #175A8C); }
.prod-link { color: var(--q-primary); cursor: pointer; }
.prod-link:hover { text-decoration: underline; }
.min-w-0 { min-width: 0; }
</style>
