<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="point_of_sale" controlRoomButton="false" class="q-mt-xs">{{ $t('CounterEndOfDay') }}</m-header>
        </div>

        <!-- ══ The cashier's hand-in: till vs system, on one band ══ -->
        <div v-if="canSubmit" class="col-12 q-mt-sm">
          <div class="eod-hero">
            <div class="eod-hero__left">
              <div class="eod-hero__eyebrow"><q-icon name="today" size="13px" /> {{ today }} · {{ counterName }}</div>
              <div class="eod-hero__val">{{ fmt(report.expected_cash) }}</div>
              <div class="eod-hero__lbl">{{ $t('ExpectedInDrawer') }} — {{ $t('ExpectedFormula') }}</div>
              <div class="eod-hero__chips">
                <span class="eod-chip"><q-icon name="savings" size="13px" />{{ $t('OpeningFloat') }} <b>{{ fmt(report.opening_float) }}</b></span>
                <span class="eod-chip"><q-icon name="payments" size="13px" />{{ $t('CashSales') }} <b>{{ fmt(report.cash_sales) }}</b></span>
                <span class="eod-chip"><q-icon name="credit_card" size="13px" />{{ $t('CardSales') }} <b>{{ fmt(report.card_sales) }}</b></span>
                <span class="eod-chip"><q-icon name="smartphone" size="13px" />{{ $t('MobileSales') }} <b>{{ fmt(report.mobile_sales) }}</b></span>
              </div>
            </div>

            <div class="eod-hero__count">
              <div class="eod-hero__countlbl">{{ $t('CountedCash') }}</div>
              <q-input dark filled dense type="number" step="0.01" min="0" v-model.number="form.counted_cash"
                class="eod-count-input" suffix="AFN" :disable="locked" @keyup.enter="submit" />
              <!-- The whole point of the page: what the drawer says vs what the
                   system says, stated before anything is submitted. -->
              <div class="eod-var" :class="varianceClass">
                <q-icon :name="varianceIcon" size="16px" />
                <span>{{ varianceLabel }}</span>
              </div>
              <div class="eod-hero__hint">{{ $t('CountedCashHint') }}</div>
            </div>

            <div class="eod-hero__cta">
              <q-btn unelevated no-caps size="md" icon="task_alt" :label="$t('SubmitEndOfDay')"
                class="eod-hero__btn" :loading="saving" :disable="locked || form.counted_cash === null || form.counted_cash === ''"
                @click="submit" />
              <q-chip v-if="locked" dense square class="q-mt-sm" color="green-6" text-color="white" icon="check_circle">
                {{ $t('AlreadySubmitted') }}
              </q-chip>
            </div>
          </div>
        </div>

        <!-- Cash in / out / notes -->
        <div v-if="canSubmit && !locked" class="col-12 q-mt-sm">
          <div class="row q-col-gutter-sm items-center q-pa-sm bg-blue-grey-1 my_radio_less" style="border-radius:10px">
            <div class="col-6 col-sm-3">
              <q-input outlined dense bg-color="white" color="primary" type="number" step="0.01" min="0"
                v-model.number="form.opening_float" :label="$t('OpeningFloat')" suffix="AFN">
                <template #prepend><q-icon name="savings" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense bg-color="white" color="primary" type="number" step="0.01" min="0"
                v-model.number="form.cash_in" :label="$t('CashIn')" suffix="AFN">
                <template #prepend><q-icon name="add_circle" color="green-7" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense bg-color="white" color="primary" type="number" step="0.01" min="0"
                v-model.number="form.cash_out" :label="$t('CashOut')" suffix="AFN">
                <template #prepend><q-icon name="remove_circle" color="deep-orange" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense bg-color="white" color="primary" v-model="form.notes" :label="$t('Notes')">
                <template #prepend><q-icon name="notes" color="primary" /></template>
              </q-input>
            </div>
          </div>
        </div>

        <!-- ══ Submissions ledger — the manager's approval desk ══ -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-md">
            <div class="col-6 col-sm-3"><stat-card dense icon="fact_check" :label="$t('TotalReports')" :value="rows.length" color="#175A8C" tint="#E0EDF7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="hourglass_top" :label="$t('PendingApproval')" :value="pendingCount" color="#D97706" tint="#FEF3C7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="check_circle" :label="$t('BalancedDays')" :value="balancedCount" color="#16A34A" tint="#DCFCE7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="balance" :label="$t('TotalVariance')" :value="fmt(totalVariance)" color="#DC2626" tint="#FEE2E2" /></div>
          </div>
        </div>

        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-sm items-center q-pa-sm bg-blue-grey-1 my_radio_less" style="border-radius:10px">
            <div class="col-6 col-sm-3">
              <q-select outlined dense bg-color="white" color="primary" v-model="filters.counter_id" :options="counterOptions"
                :label="$t('Counter')" emit-value map-options clearable @update:model-value="loadRows">
                <template #prepend><q-icon name="point_of_sale" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense bg-color="white" color="primary" type="date" v-model="filters.date_from" :label="$t('DateFrom')" @update:model-value="loadRows" />
            </div>
            <div class="col-6 col-sm-3">
              <q-input outlined dense bg-color="white" color="primary" type="date" v-model="filters.date_to" :label="$t('DateTo')" @update:model-value="loadRows" />
            </div>
          </div>
        </div>

        <action-bar :rows="rows" :columns="columns" filename="counter-end-of-day" @update:filtered="filteredRows = $event" />

        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            :noEdit="true" :noDelete="true" :noInfo="true">

            <template v-slot:body-cell-counter="props">
              <q-td :props="props">
                <div class="text-weight-bold">{{ props.row.counter?.name || '—' }}</div>
                <div class="text-caption text-grey-6">{{ props.row.branch?.name || '—' }}</div>
              </q-td>
            </template>

            <template v-slot:body-cell-cashier="props">
              <q-td :props="props">{{ props.row.cashier?.name || '—' }}</q-td>
            </template>

            <template v-slot:body-cell-expected_cash="props">
              <q-td :props="props" class="text-weight-medium">{{ fmt(props.row.expected_cash) }}</q-td>
            </template>

            <template v-slot:body-cell-counted_cash="props">
              <q-td :props="props" class="text-weight-bold">{{ fmt(props.row.counted_cash) }}</q-td>
            </template>

            <template v-slot:body-cell-variance="props">
              <q-td :props="props">
                <span class="eod-diff" :class="rowVarClass(props.row.variance)">
                  {{ varText(props.row.variance) }}
                </span>
              </q-td>
            </template>

            <template v-slot:body-cell-status="props">
              <q-td :props="props">
                <q-chip dense size="sm" :color="statusColor(props.row.status)" text-color="white">{{ $t(statusKey(props.row.status)) }}</q-chip>
              </q-td>
            </template>

            <template v-slot:body-cell-eod_actions="props">
              <q-td :props="props" class="text-right">
                <template v-if="props.row.status === 'submitted' && canApprove">
                  <q-btn size="sm" dense color="green-7" icon="check" class="q-ml-xs" @click="decide(props.row, 'approve')">
                    <q-tooltip>{{ $t('Approved') }}</q-tooltip>
                  </q-btn>
                  <q-btn size="sm" dense color="negative" icon="close" class="q-ml-xs" @click="decide(props.row, 'reject')">
                    <q-tooltip>{{ $t('Rejected') }}</q-tooltip>
                  </q-btn>
                </template>
                <span v-else class="text-grey-4">—</span>
              </q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()
const can = (p) => (proxy?.$can ? proxy.$can(p) : false)

const today = new Date().toISOString().slice(0, 10)
const rows = ref([])
const filteredRows = ref([])
const loading = ref(false)
const saving = ref(false)
const filter = ref('')
const report = ref({})
const counterId = ref(null)
const counterName = ref('')
const counterOptions = ref([])
const filters = reactive({ counter_id: null, date_from: '', date_to: '' })

// A cashier submits their own till; a manager approves what came in.
const canSubmit = computed(() => !!counterId.value)
const canApprove = computed(() => can('approve-counter-reports'))
const locked = computed(() => ['submitted', 'approved'].includes(report.value?.status))

const form = reactive({ opening_float: 0, counted_cash: null, cash_in: 0, cash_out: 0, notes: '' })

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'report_date', label: 'Date', field: 'report_date', align: 'left', sortable: true },
  { name: 'counter', label: 'Counter', field: row => row.counter?.name, align: 'left', sortable: true },
  { name: 'cashier', label: 'Cashier', field: row => row.cashier?.name, align: 'left' },
  { name: 'expected_cash', label: 'ExpectedCash', field: 'expected_cash', align: 'left', sortable: true },
  { name: 'counted_cash', label: 'CountedCash', field: 'counted_cash', align: 'left', sortable: true },
  { name: 'variance', label: 'Variance', field: 'variance', align: 'left', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
  { name: 'eod_actions', label: 'Actions', field: 'id', align: 'right' },
]

function fmt (v) { return (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' AFN' }
function statusColor (s) { return s === 'approved' ? 'green-7' : s === 'rejected' ? 'negative' : s === 'submitted' ? 'orange-8' : 'grey-6' }
function statusKey (s) { return s === 'approved' ? 'Approved' : s === 'rejected' ? 'Rejected' : s === 'submitted' ? 'Submitted' : 'SaveDraft' }

// Backend convention: variance = expected − counted, so a POSITIVE number
// means the drawer is SHORT of what the system expected.
const liveVariance = computed(() => {
  const expected = Number(report.value?.expected_cash) || 0
  const counted = Number(form.counted_cash)
  if (form.counted_cash === null || form.counted_cash === '') return null
  return Number((expected - counted).toFixed(2))
})
const varianceClass = computed(() => {
  const v = liveVariance.value
  if (v === null) return 'eod-var--idle'
  return v === 0 ? 'eod-var--ok' : v > 0 ? 'eod-var--short' : 'eod-var--over'
})
const varianceIcon = computed(() => {
  const v = liveVariance.value
  if (v === null) return 'calculate'
  return v === 0 ? 'check_circle' : 'error'
})
const varianceLabel = computed(() => {
  const v = liveVariance.value
  if (v === null) return proxy.$t('CountedCash')
  if (v === 0) return proxy.$t('TillMatches')
  return (v > 0 ? proxy.$t('ShortBy') : proxy.$t('OverBy')) + ' ' + fmt(Math.abs(v))
})
function rowVarClass (v) {
  const n = Number(v) || 0
  return n === 0 ? 'eod-diff--ok' : n > 0 ? 'eod-diff--short' : 'eod-diff--over'
}
function varText (v) {
  const n = Number(v) || 0
  if (n === 0) return proxy.$t('Balanced')
  return (n > 0 ? proxy.$t('Short') : proxy.$t('Over')) + ' ' + fmt(Math.abs(n))
}

const pendingCount = computed(() => rows.value.filter(r => r.status === 'submitted').length)
const balancedCount = computed(() => rows.value.filter(r => Number(r.variance) === 0).length)
const totalVariance = computed(() => rows.value.reduce((s, r) => s + (Number(r.variance) || 0), 0))

async function loadCounters () {
  try {
    const { data } = await api.get('/counters')
    const list = data?.data || data || []
    counterOptions.value = list.map(c => ({ label: c.name, value: c.id }))
    return list
  } catch (_) { return [] }
}

/** The seat this cashier is on — their open shift decides it. */
async function resolveMyCounter (list) {
  try {
    const { data } = await api.get('/shifts/current')
    const cid = data?.counter_id ?? data?.shift?.counter_id ?? null
    if (cid) {
      counterId.value = cid
      counterName.value = list.find(c => c.id === cid)?.name || ''
      return
    }
  } catch (_) {}
  // No open shift: fall back to the first seat so a manager can still review.
  if (list.length) {
    counterId.value = list[0].id
    counterName.value = list[0].name
  }
}

async function loadToday () {
  if (!counterId.value) return
  try {
    const { data } = await api.get(`/counters/${counterId.value}/eod`)
    report.value = data || {}
    form.opening_float = Number(data?.opening_float) || 0
    form.cash_in = Number(data?.cash_in) || 0
    form.cash_out = Number(data?.cash_out) || 0
    form.notes = data?.notes || ''
    if (data?.counted_cash != null && Number(data.counted_cash) > 0) form.counted_cash = Number(data.counted_cash)
  } catch (_) { report.value = {} }
}

async function loadRows () {
  loading.value = true
  try {
    const params = { per_page: 500 }
    for (const k of ['counter_id', 'date_from', 'date_to']) if (filters[k]) params[k] = filters[k]
    const { data } = await api.get('/counter-eod', { params })
    rows.value = data?.data || data || []
  } finally { loading.value = false }
}

async function submit () {
  if (locked.value) return
  saving.value = true
  try {
    await api.post(`/counters/${counterId.value}/eod/submit`, { ...form })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'task_alt', message: proxy.$t('Submitted') })
    loadToday(); loadRows()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Submit failed' })
  } finally { saving.value = false }
}

async function decide (row, action) {
  try {
    await api.post(`/counter-eod/${row.id}/${action}`)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'done', message: proxy.$t(action === 'approve' ? 'Approved' : 'Rejected') })
    loadRows(); loadToday()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  }
}

onMounted(async () => {
  const list = await loadCounters()
  await resolveMyCounter(list)
  loadToday()
  loadRows()
})
</script>

<style scoped>
/* The hand-in band — navy cockpit, gold submit, variance stated loudly */
.eod-hero {
  position: relative; display: flex; align-items: stretch; gap: 24px; flex-wrap: wrap;
  background: linear-gradient(118deg, #0E2A47 0%, #123A66 52%, #17517F 100%);
  border-radius: 18px; padding: 20px 24px; color: #fff; overflow: hidden;
  box-shadow: 0 24px 44px -26px rgba(14, 42, 71, 0.9);
}
.eod-hero::after {
  content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 3px;
  background: linear-gradient(90deg, transparent, #C8862D 30%, #F3D48B 50%, #C8862D 70%, transparent);
}
.eod-hero__left { flex: 1 1 280px; min-width: 0; }
.eod-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10.5px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #F3D48B;
}
.eod-hero__val { font-size: 32px; font-weight: 900; letter-spacing: -0.8px; line-height: 1.1; margin-top: 4px; font-variant-numeric: tabular-nums; }
.eod-hero__lbl { font-size: 11px; color: #9FC1E0; margin-top: 2px; }
.eod-hero__chips { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 12px; }
.eod-chip {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 9px; padding: 4px 9px; font-size: 10.5px; color: #C7DCEF;
}
.eod-chip b { color: #fff; font-variant-numeric: tabular-nums; }

.eod-hero__count { flex: 1 1 240px; display: flex; flex-direction: column; justify-content: center; }
.eod-hero__countlbl { font-size: 10.5px; font-weight: 800; letter-spacing: 1.6px; text-transform: uppercase; color: #9FC1E0; margin-bottom: 5px; }
.eod-count-input :deep(.q-field__control) { border-radius: 12px; background: rgba(255, 255, 255, 0.1); }
.eod-count-input :deep(input) { font-size: 24px; font-weight: 900; text-align: end; font-variant-numeric: tabular-nums; }
.eod-var {
  display: flex; align-items: center; gap: 7px; margin-top: 9px;
  border-radius: 10px; padding: 7px 11px; font-size: 12.5px; font-weight: 800;
}
.eod-var--idle { background: rgba(255, 255, 255, 0.08); color: #9FC1E0; }
.eod-var--ok { background: rgba(16, 185, 129, 0.18); color: #6EE7B7; }
.eod-var--short { background: rgba(220, 38, 38, 0.2); color: #FCA5A5; }
.eod-var--over { background: rgba(217, 119, 6, 0.22); color: #FDBA74; }
.eod-hero__hint { font-size: 10px; color: #9FC1E0; margin-top: 6px; line-height: 1.4; }

.eod-hero__cta { display: flex; flex-direction: column; justify-content: center; min-width: 180px; }
.eod-hero__btn {
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
  font-weight: 900; border-radius: 12px;
}

.eod-diff { display: inline-block; font-size: 12px; font-weight: 800; padding: 2px 8px; border-radius: 8px; }
.eod-diff--ok { background: #DCFCE7; color: #15803D; }
.eod-diff--short { background: #FEE2E2; color: #B91C1C; }
.eod-diff--over { background: #FFEDD5; color: #C2410C; }
</style>
