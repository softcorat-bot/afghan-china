<template>
  <q-page class="q-pa-md sh-page">
    <div class="row items-center q-mb-md">
      <div class="sh-title"><q-icon name="point_of_sale" size="24px" class="q-mr-sm" />{{ $t('CashRegister') }}</div>
    </div>

    <!-- ── NO OPEN SHIFT: open card ─────────────────────────── -->
    <q-card v-if="!open" flat bordered class="sh-open-card">
      <div class="text-center q-pa-lg">
        <q-icon name="lock_open" size="52px" color="primary" />
        <div class="text-h6 q-mt-sm">{{ $t('NoOpenShift') }}</div>
        <div class="text-grey-6 q-mb-md">{{ $t('OpenShiftHint') }}</div>
        <q-select outlined dense v-model="counterId" :options="counterOptions" emit-value map-options :label="$t('Counter')" style="max-width:240px;margin:0 auto 8px">
          <template #prepend><q-icon name="point_of_sale" color="primary" /></template>
        </q-select>
        <q-input outlined dense type="number" min="0" v-model.number="openingFloat" :label="$t('OpeningFloat')" suffix="AFN" style="max-width:240px;margin:0 auto" @keydown.enter="openShift">
          <template #prepend><q-icon name="account_balance_wallet" color="primary" /></template>
        </q-input>
        <q-btn class="q-mt-md" unelevated no-caps color="primary" icon="play_arrow" :label="$t('OpenShift')" :loading="busy" @click="openShift" />
      </div>
    </q-card>

    <!-- ── OPEN SHIFT: live drawer ──────────────────────────── -->
    <template v-else>
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-6 col-md-3"><stat-card icon="schedule" :label="$t('ShiftOpenedAt')" :value="time(open.opened_at)" color="#175A8C" tint="#E0EDF7" :sub="open.cashier?.name" sub-icon="person" /></div>
        <div class="col-6 col-md-3"><stat-card icon="account_balance_wallet" :label="$t('OpeningFloat')" :value="money(open.opening_float)" color="#7C3AED" tint="#EDE9FE" /></div>
        <div class="col-6 col-md-3"><stat-card icon="receipt_long" :label="$t('Orders')" :value="live.orders_count" color="#D97706" tint="#FEF3C7" :sub="money(live.total_sales)" sub-icon="sell" /></div>
        <div class="col-6 col-md-3"><stat-card icon="savings" :label="$t('ExpectedCash')" :value="money(live.expected_cash)" color="#16A34A" tint="#DCFCE7" :sub="$t('InDrawer')" sub-icon="point_of_sale" /></div>
      </div>

      <div class="row q-col-gutter-md">
        <!-- drawer breakdown -->
        <div class="col-12 col-md-7">
          <q-card flat bordered class="sh-card">
            <div class="sh-card__head"><q-icon name="calculate" size="18px" class="q-mr-xs" />{{ $t('DrawerBreakdown') }}</div>
            <div class="q-pa-md">
              <div class="sh-row"><span>{{ $t('OpeningFloat') }}</span><b>{{ money(open.opening_float) }}</b></div>
              <div class="sh-row"><span><span class="sh-dot" style="background:#16A34A"></span>{{ $t('CashSales') }}</span><b class="text-positive">+ {{ money(live.cash_sales) }}</b></div>
              <div class="sh-row"><span><span class="sh-dot" style="background:#175A8C"></span>{{ $t('CardSales') }}</span><span class="text-grey-6">{{ money(live.card_sales) }}</span></div>
              <div class="sh-row"><span><span class="sh-dot" style="background:#7C3AED"></span>{{ $t('MobileSales') }}</span><span class="text-grey-6">{{ money(live.mobile_sales) }}</span></div>
              <div class="sh-row"><span>{{ $t('CashIn') }}</span><b class="text-positive">+ {{ money(live.cash_in) }}</b></div>
              <div class="sh-row"><span>{{ $t('CashOut') }}</span><b class="text-negative">- {{ money(live.cash_out) }}</b></div>
              <q-separator class="q-my-sm" />
              <div class="sh-row sh-row--total"><span>{{ $t('ExpectedInDrawer') }}</span><b>{{ money(live.expected_cash) }}</b></div>
              <div class="text-caption text-grey-5 q-mt-xs">{{ $t('ExpectedFormula') }}</div>
            </div>
            <q-separator />
            <div class="q-pa-sm row q-gutter-sm justify-end">
              <q-btn outline no-caps color="positive" icon="add" :label="$t('CashIn')" @click="openMovement('in')" />
              <q-btn outline no-caps color="negative" icon="remove" :label="$t('CashOut')" @click="openMovement('out')" />
              <q-btn outline no-caps color="primary" icon="summarize" :label="$t('XReport')" @click="showX = true" />
              <q-btn unelevated no-caps color="primary" icon="lock" :label="$t('CloseShift')" @click="openClose" />
            </div>
          </q-card>
        </div>

        <!-- cash movements log -->
        <div class="col-12 col-md-5">
          <q-card flat bordered class="sh-card">
            <div class="sh-card__head"><q-icon name="swap_vert" size="18px" class="q-mr-xs" />{{ $t('CashMovements') }}</div>
            <q-scroll-area style="height:280px">
              <div v-if="!(open.movements || []).length" class="text-grey-5 text-center q-pa-lg">{{ $t('NoMovements') }}</div>
              <div v-for="m in open.movements" :key="m.id" class="sh-mv">
                <q-icon :name="m.type === 'in' ? 'arrow_downward' : 'arrow_upward'" :color="m.type === 'in' ? 'positive' : 'negative'" size="18px" />
                <div class="col">
                  <div class="text-weight-medium">{{ m.reason || (m.type === 'in' ? $t('CashIn') : $t('CashOut')) }}</div>
                  <div class="text-caption text-grey-5">{{ m.user?.name }} · {{ time(m.created_at) }}</div>
                </div>
                <div :class="m.type === 'in' ? 'text-positive' : 'text-negative'" class="text-weight-bold">{{ m.type === 'in' ? '+' : '-' }} {{ money(m.amount) }}</div>
              </div>
            </q-scroll-area>
          </q-card>
        </div>
      </div>
    </template>

    <!-- ── history ──────────────────────────────────────────── -->
    <div class="sh-section">{{ $t('ShiftHistory') }}</div>
    <n-table :data="history" :columns="columns" row-key="id" :loading="loading" v-model:filter="filter">
      <template v-slot:body-cell-variance="props">
        <q-td :props="props">
          <q-badge v-if="props.row.variance != null" :color="varColor(props.row.variance)">{{ varText(props.row.variance) }}</q-badge>
          <span v-else class="text-grey-5">—</span>
        </q-td>
      </template>
      <template v-slot:body-cell-status="props">
        <q-td :props="props"><q-chip dense size="sm" :color="props.row.status === 'open' ? 'positive' : 'grey'" text-color="white">{{ $t(props.row.status === 'open' ? 'Open' : 'Closed') }}</q-chip></q-td>
      </template>
      <template v-slot:body-cell-zops="props">
        <q-td :props="props" class="text-right"><q-btn round flat dense color="info" icon="summarize" @click="viewZ(props.row)"><q-tooltip>{{ $t('ZReport') }}</q-tooltip></q-btn></q-td>
      </template>
    </n-table>

    <!-- movement dialog -->
    <q-dialog v-model="mvDialog">
      <q-card style="width:380px">
        <n-header :icon="mvType === 'in' ? 'add' : 'remove'">{{ mvType === 'in' ? $t('CashIn') : $t('CashOut') }}</n-header>
        <q-separator />
        <q-card-section>
          <q-input outlined dense type="number" min="0" v-model.number="mvForm.amount" :label="$t('Amount')" suffix="AFN" autofocus />
          <q-input class="q-mt-sm" outlined dense v-model="mvForm.reason" :label="$t('Reason')" />
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="primary" :label="$t('Save')" :loading="busy" @click="saveMovement" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- X report dialog -->
    <q-dialog v-model="showX">
      <q-card class="sh-report">
        <div class="sh-report__top"><q-icon name="summarize" size="40px" color="primary" /><div class="text-h6">{{ $t('XReport') }}</div><div class="text-grey-6">{{ $t('LiveSnapshot') }}</div></div>
        <q-separator />
        <q-card-section>
          <div class="sh-row"><span>{{ $t('OpeningFloat') }}</span><b>{{ money(open?.opening_float) }}</b></div>
          <div class="sh-row"><span>{{ $t('CashSales') }}</span><b>{{ money(live.cash_sales) }}</b></div>
          <div class="sh-row"><span>{{ $t('CardSales') }}</span><span>{{ money(live.card_sales) }}</span></div>
          <div class="sh-row"><span>{{ $t('MobileSales') }}</span><span>{{ money(live.mobile_sales) }}</span></div>
          <div class="sh-row"><span>{{ $t('CashIn') }} / {{ $t('CashOut') }}</span><span>+{{ money(live.cash_in) }} / -{{ money(live.cash_out) }}</span></div>
          <div class="sh-row"><span>{{ $t('Orders') }}</span><span>{{ live.orders_count }}</span></div>
          <q-separator class="q-my-sm" />
          <div class="sh-row sh-row--total"><span>{{ $t('ExpectedInDrawer') }}</span><b>{{ money(live.expected_cash) }}</b></div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md"><q-btn flat no-caps color="grey-7" icon="print" :label="$t('Print')" @click="printX" /><q-btn unelevated no-caps color="primary" :label="$t('Close')" v-close-popup /></q-card-actions>
      </q-card>
    </q-dialog>

    <!-- close shift dialog -->
    <q-dialog v-model="closeDialog">
      <q-card class="sh-report">
        <n-header icon="lock">{{ $t('CloseShift') }}</n-header>
        <q-separator />
        <q-card-section>
          <div class="sh-row"><span>{{ $t('ExpectedInDrawer') }}</span><b>{{ money(live.expected_cash) }}</b></div>
          <q-input class="q-mt-sm" outlined dense type="number" min="0" v-model.number="countedCash" :label="$t('CountedCash')" suffix="AFN" autofocus>
            <template #prepend><q-icon name="payments" color="primary" /></template>
          </q-input>
          <div class="sh-variance q-mt-md" :class="varClass(variance)">
            <span>{{ variance === 0 ? $t('Balanced') : (variance > 0 ? $t('Over') : $t('Short')) }}</span>
            <b>{{ money(Math.abs(variance)) }}</b>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="primary" icon="lock" :label="$t('CloseShift')" :loading="busy" @click="closeShift" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Z report dialog -->
    <q-dialog v-model="zDialog">
      <q-card v-if="zShift" class="sh-report">
        <div class="sh-report__top"><q-icon name="summarize" size="40px" :color="varColor(zShift.variance)" /><div class="text-h6">{{ $t('ZReport') }} #{{ zShift.id }}</div><div class="text-grey-6">{{ time(zShift.opened_at) }} → {{ time(zShift.closed_at) }}</div></div>
        <q-separator />
        <q-card-section>
          <div class="sh-row"><span>{{ $t('OpeningFloat') }}</span><b>{{ money(zShift.opening_float) }}</b></div>
          <div class="sh-row"><span>{{ $t('CashSales') }}</span><span>{{ money(zShift.cash_sales) }}</span></div>
          <div class="sh-row"><span>{{ $t('CardSales') }}</span><span>{{ money(zShift.card_sales) }}</span></div>
          <div class="sh-row"><span>{{ $t('MobileSales') }}</span><span>{{ money(zShift.mobile_sales) }}</span></div>
          <div class="sh-row"><span>{{ $t('CashIn') }} / {{ $t('CashOut') }}</span><span>+{{ money(zShift.cash_in) }} / -{{ money(zShift.cash_out) }}</span></div>
          <div class="sh-row"><span>{{ $t('TotalSales') }} · {{ $t('Orders') }}</span><span>{{ money(zShift.total_sales) }} · {{ zShift.orders_count }}</span></div>
          <q-separator class="q-my-sm" />
          <div class="sh-row"><span>{{ $t('ExpectedInDrawer') }}</span><b>{{ money(zShift.expected_cash) }}</b></div>
          <div class="sh-row"><span>{{ $t('CountedCash') }}</span><b>{{ money(zShift.counted_cash) }}</b></div>
          <div class="sh-variance q-mt-sm" :class="varClass(zShift.variance)"><span>{{ zShift.variance == 0 ? $t('Balanced') : (zShift.variance > 0 ? $t('Over') : $t('Short')) }}</span><b>{{ money(Math.abs(zShift.variance)) }}</b></div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md"><q-btn flat no-caps color="grey-7" icon="print" :label="$t('Print')" @click="printZ(zShift)" /><q-btn unelevated no-caps color="primary" :label="$t('Close')" v-close-popup /></q-card-actions>
      </q-card>
    </q-dialog>

    <shift-report-print ref="reportRef" :data="reportData" />
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { useQuasar } from 'quasar'
import { api } from '@/boot/axios'
import ShiftReportPrint from '@/components/ShiftReportPrint.vue'

const $q = useQuasar()
const { proxy } = getCurrentInstance()
const t = (k) => (proxy?.$t ? proxy.$t(k) : k)
const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' AFN'
const time = (v) => v ? new Date(v).toLocaleString() : '—'

const open = ref(null)
const history = ref([])
const loading = ref(false)
const busy = ref(false)
const filter = ref('')
const openingFloat = ref(0)

// Physical counter seat this drawer session runs on.
const counterId = ref(null)
const counterOptions = ref([])
async function loadCounters () {
  try {
    const { data } = await api.get('/counters')
    counterOptions.value = (data || []).filter(c => c.active)
      .map(c => ({ label: c.name + (c.shift_open ? ` — ${c.operator}` : ''), value: c.id, disable: c.shift_open }))
    const free = counterOptions.value.find(o => !o.disable)
    if (free && counterId.value === null) counterId.value = free.value
  } catch (_) {}
}
loadCounters()

const live = computed(() => open.value?.live || { cash_sales: 0, card_sales: 0, mobile_sales: 0, cash_in: 0, cash_out: 0, total_sales: 0, orders_count: 0, expected_cash: 0 })

const columns = [
  { name: 'id', label: '#', field: 'id', align: 'left', sortable: true },
  { name: 'cashier', label: t('Cashier'), field: r => r.cashier?.name || '—', align: 'left' },
  { name: 'opened_at', label: t('Opened'), field: r => time(r.opened_at), align: 'left' },
  { name: 'closed_at', label: t('Closed'), field: r => r.closed_at ? time(r.closed_at) : '—', align: 'left' },
  { name: 'total_sales', label: t('TotalSales'), field: r => money(r.total_sales), align: 'right' },
  { name: 'variance', label: t('Variance'), field: 'variance', align: 'center' },
  { name: 'status', label: t('Status'), field: 'status', align: 'center' },
  { name: 'zops', label: '', field: 'zops', align: 'right' },
]

async function loadCurrent () {
  try { const { data } = await api.get('/shifts/current'); open.value = (data && data.id) ? data : null } catch (_) { open.value = null }
}
async function loadHistory () {
  loading.value = true
  try { const { data } = await api.get('/shifts'); history.value = data || [] } catch (_) {} finally { loading.value = false }
}
async function refresh () { await Promise.all([loadCurrent(), loadHistory()]) }
onMounted(refresh)

async function openShift () {
  if (openingFloat.value == null || openingFloat.value < 0) return
  busy.value = true
  try {
    const { data } = await api.post('/shifts/open', { opening_float: openingFloat.value, counter_id: counterId.value })
    open.value = data
    notify(t('ShiftOpened'), 'positive')
    loadHistory()
  } catch (e) { notify(e?.response?.data?.message || t('SaveFailed'), 'negative') } finally { busy.value = false }
}

// movements
const mvDialog = ref(false)
const mvType = ref('in')
const mvForm = reactive({ amount: 0, reason: '' })
function openMovement (type) { mvType.value = type; mvForm.amount = 0; mvForm.reason = ''; mvDialog.value = true }
async function saveMovement () {
  if (!mvForm.amount || mvForm.amount <= 0) return
  busy.value = true
  try {
    const { data } = await api.post(`/shifts/${open.value.id}/movement`, { type: mvType.value, amount: mvForm.amount, reason: mvForm.reason })
    open.value = data
    mvDialog.value = false
    notify(t('Saved'), 'positive')
  } catch (e) { notify(e?.response?.data?.message || t('SaveFailed'), 'negative') } finally { busy.value = false }
}

// X report
const showX = ref(false)

// close
const closeDialog = ref(false)
const countedCash = ref(0)
const variance = computed(() => Math.round(((Number(countedCash.value) || 0) - Number(live.value.expected_cash)) * 100) / 100)
function openClose () { countedCash.value = Number(live.value.expected_cash); closeDialog.value = true }
async function closeShift () {
  busy.value = true
  try {
    await api.post(`/shifts/${open.value.id}/close`, { counted_cash: countedCash.value })
    closeDialog.value = false
    open.value = null
    notify(t('ShiftClosed'), 'positive')
    refresh()
  } catch (e) { notify(e?.response?.data?.message || t('SaveFailed'), 'negative') } finally { busy.value = false }
}

// Z report
const zDialog = ref(false)
const zShift = ref(null)
async function viewZ (row) {
  try { const { data } = await api.get(`/shifts/${row.id}`); zShift.value = data; zDialog.value = true } catch (_) {}
}

function varColor (v) { return v == 0 ? 'positive' : (Math.abs(v) < 1 ? 'positive' : (v > 0 ? 'blue' : 'negative')) }
function varText (v) { return (v > 0 ? '+' : (v < 0 ? '-' : '')) + money(Math.abs(v)) }
function varClass (v) { return v === 0 ? 'sh-variance--ok' : (v > 0 ? 'sh-variance--over' : 'sh-variance--short') }
function notify (message, color = 'primary') { $q.notify({ message, color, position: 'top', timeout: 2000 }) }
// ── thermal X/Z report printing ──
const reportRef = ref(null)
const reportData = ref(null)
const companyName = ref('Afghan China Shopping Center')
api.get('/user').then(({ data }) => { companyName.value = data?.company?.name_en || companyName.value }).catch(() => {})

function printX () {
  if (!open.value) return
  reportData.value = {
    type: 'x', store: companyName.value, shiftId: open.value.id, cashier: open.value.cashier?.name,
    openedAt: open.value.opened_at, closedAt: null,
    openingFloat: open.value.opening_float,
    cashSales: live.value.cash_sales, cardSales: live.value.card_sales, mobileSales: live.value.mobile_sales,
    cashIn: live.value.cash_in, cashOut: live.value.cash_out, expectedCash: live.value.expected_cash,
    orders: live.value.orders_count, totalSales: live.value.total_sales,
  }
  reportRef.value?.print()
}
function printZ (s) {
  if (!s) return
  reportData.value = {
    type: 'z', store: companyName.value, shiftId: s.id, cashier: s.cashier?.name,
    openedAt: s.opened_at, closedAt: s.closed_at,
    openingFloat: s.opening_float,
    cashSales: s.cash_sales, cardSales: s.card_sales, mobileSales: s.mobile_sales,
    cashIn: s.cash_in, cashOut: s.cash_out, expectedCash: s.expected_cash,
    countedCash: s.counted_cash, variance: s.variance,
    orders: s.orders_count, totalSales: s.total_sales,
  }
  reportRef.value?.print()
}
</script>

<style scoped>
.sh-page { background: #F0F4F8; }
.sh-title { font-size: 20px; font-weight: 800; color: #0F172A; display: flex; align-items: center; }
.sh-section { font-size: 15px; font-weight: 800; color: #175A8C; margin: 22px 0 10px; }
.sh-open-card { border-radius: 16px; max-width: 440px; margin: 40px auto; }
.sh-card { border-radius: 16px; }
.sh-card__head { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; padding: 12px 14px 8px; }
.sh-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 14px; color: #334155; }
.sh-row--total { font-size: 17px; font-weight: 800; color: #0F172A; }
.sh-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-inline-end: 7px; }
.sh-mv { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-bottom: 1px dashed #EEF2F6; }
.sh-report { width: 400px; max-width: 94vw; }
.sh-report__top { text-align: center; padding: 18px; }
.sh-variance { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-radius: 10px; font-weight: 800; font-size: 16px; }
.sh-variance--ok { background: #DCFCE7; color: #16A34A; }
.sh-variance--over { background: #E0EDF7; color: #175A8C; }
.sh-variance--short { background: #FEE2E2; color: #DC2626; }
</style>
