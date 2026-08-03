<template>
  <q-page class="mc-page">
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="mdi-diamond-stone" controlRoomButton="false" class="q-mt-xs">{{ $t('MainCost') }}</m-header>
        </div>

        <!-- Two tabs, not five: the number the owner came for, and the prices
             that produce it. Everything else was noise. -->
        <div class="col-12 q-mt-sm">
          <q-tabs v-model="tab" dense no-caps align="left" class="mc-tabs"
            active-color="amber-9" indicator-color="amber-8">
            <q-tab name="profit" icon="savings" :label="$t('NetProfit2')" />
            <q-tab name="prices" icon="mdi-diamond-stone" :label="$t('SetMainPrice')" />
            <q-tab name="history" icon="history" :label="$t('PriceHistory')" />
          </q-tabs>
        </div>

        <!-- ═══════════ فایده خالص — NET PROFIT ═══════════ -->
        <template v-if="tab === 'profit'">
          <!-- One row of choices: when, where, which till. -->
          <div class="col-12 q-mt-sm">
            <div class="mc-filters">
              <q-btn-toggle v-model="pf.period" dense unelevated no-caps spread
                toggle-color="amber-9" color="white" text-color="blue-grey-8"
                class="mc-periods"
                :options="PERIODS.map(p => ({ label: $t(p.label), value: p.value }))"
                @update:model-value="onPeriod" />

              <q-select outlined dense v-model="pf.branch_id" :options="branchOptions"
                :label="$t('Branch')" emit-value map-options clearable bg-color="white"
                class="mc-filter" @update:model-value="onBranch">
                <template #prepend><q-icon name="store" color="amber-9" /></template>
              </q-select>

              <q-select outlined dense v-model="pf.counter_id" :options="counterOptions"
                :label="$t('Counter')" emit-value map-options clearable bg-color="white"
                class="mc-filter" @update:model-value="load">
                <template #prepend><q-icon name="point_of_sale" color="amber-9" /></template>
              </q-select>

              <q-input v-if="pf.period === 'custom'" outlined dense type="date" bg-color="white"
                v-model="pf.from" :label="$t('DateFrom')" class="mc-filter" @update:model-value="load" />
              <q-input v-if="pf.period === 'custom'" outlined dense type="date" bg-color="white"
                v-model="pf.to" :label="$t('DateTo')" class="mc-filter" @update:model-value="load" />

              <q-space />
              <export-btn :data="exportRows" :columns="exportColumns" filename="net-profit" />
            </div>
          </div>

          <!-- The answer, stated once and large. -->
          <div class="col-12 q-mt-sm">
            <div class="mc-hero" :class="{ 'mc-hero--loss': net < 0 }">
              <div class="mc-hero__main">
                <div class="mc-hero__eyebrow">
                  <q-icon name="mdi-diamond-stone" size="13px" /> {{ $t('PureProfit') }}
                </div>
                <div class="mc-hero__value">
                  <span v-if="loading" class="mc-hero__skeleton"></span>
                  <template v-else>{{ money(net) }}<small> AFN</small></template>
                </div>
                <div class="mc-hero__scope">
                  {{ scopeLabel }} · {{ data.period?.from }} → {{ data.period?.to }}
                </div>
              </div>

              <!-- The subtraction, shown so the number can be trusted. -->
              <div class="mc-calc">
                <div class="mc-calc__item">
                  <span>{{ $t('Revenue') }}</span>
                  <b>{{ money(totals.revenue) }}</b>
                </div>
                <div class="mc-calc__op">−</div>
                <div class="mc-calc__item">
                  <span>{{ $t('MinusMainCost') }}</span>
                  <b>{{ money(totals.cogs) }}</b>
                </div>
                <div class="mc-calc__op">−</div>
                <div class="mc-calc__item">
                  <span>{{ $t('MinusExpenses') }}</span>
                  <b>{{ money(totals.expenses) }}</b>
                </div>
                <div class="mc-calc__op mc-calc__op--eq">=</div>
                <div class="mc-calc__item mc-calc__item--net">
                  <span>{{ $t('EqualsNet') }}</span>
                  <b>{{ money(net) }}</b>
                </div>
              </div>

              <div class="mc-hero__side">
                <div class="mc-kpi"><b>{{ totals.margin ?? 0 }}%</b><span>{{ $t('NetMargin') }}</span></div>
                <div class="mc-kpi"><b>{{ totals.orders ?? 0 }}</b><span>{{ $t('Orders') }}</span></div>
              </div>
            </div>
            <div class="mc-hint">{{ $t('ProfitFormulaHint') }}</div>
          </div>

          <!-- The shape behind the figure. -->
          <div v-if="(data.trend || []).length > 1" class="col-12 q-mt-sm">
            <q-card flat bordered class="mc-card">
              <q-card-section class="q-pb-none">
                <div class="mc-card__title"><q-icon name="show_chart" size="17px" class="q-mr-xs" />{{ $t('NetProfit2') }}</div>
              </q-card-section>
              <q-card-section>
                <div class="mc-trend">
                  <div v-for="(b, i) in data.trend" :key="i" class="mc-trend__col">
                    <div class="mc-trend__bar" :class="{ 'mc-trend__bar--loss': b.net_profit < 0 }"
                      :style="`height:${trendPct(b)}%`">
                      <q-tooltip>{{ b.label }} · {{ money(b.net_profit) }}</q-tooltip>
                    </div>
                    <span>{{ b.label }}</span>
                  </div>
                </div>
              </q-card-section>
            </q-card>
          </div>

          <!-- Branch by branch, each opening onto its counters. -->
          <div class="col-12 q-mt-sm">
            <q-card flat bordered class="mc-card">
              <q-card-section class="q-pb-xs row items-center">
                <div class="mc-card__title"><q-icon name="store" size="17px" class="q-mr-xs" />{{ $t('PerBranch') }}</div>
                <q-space />
                <div class="text-caption text-grey-6">{{ $t('PerCounter') }}</div>
              </q-card-section>
              <q-separator />
              <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-9" size="32px" /></div>
              <div v-else-if="!(data.branches || []).length" class="q-py-xl text-center text-grey-6">{{ $t('NoProfitData') }}</div>
              <q-list v-else separator>
                <q-expansion-item v-for="b in data.branches" :key="b.branch_id"
                  :default-opened="(data.branches || []).length <= 2" dense-toggle>
                  <template #header>
                    <q-item-section avatar>
                      <q-avatar size="34px" :color="b.net_profit >= 0 ? 'green-1' : 'red-1'"
                        :text-color="b.net_profit >= 0 ? 'green-8' : 'red-7'" icon="store" />
                    </q-item-section>
                    <q-item-section>
                      <q-item-label class="text-weight-bold">{{ b.branch }}</q-item-label>
                      <q-item-label caption>
                        {{ $t('Revenue') }} {{ money(b.revenue) }} · {{ $t('MinusExpenses') }} {{ money(b.expenses) }}
                      </q-item-label>
                    </q-item-section>
                    <q-item-section side>
                      <div class="mc-net" :class="b.net_profit >= 0 ? 'mc-net--up' : 'mc-net--down'">
                        {{ money(b.net_profit) }}
                      </div>
                      <div class="text-caption text-grey-6 text-right">{{ b.margin }}%</div>
                    </q-item-section>
                  </template>

                  <q-markup-table flat dense class="mc-sub">
                    <thead>
                      <tr>
                        <th class="text-left">{{ $t('Counter') }}</th>
                        <th class="text-right">{{ $t('Orders') }}</th>
                        <th class="text-right">{{ $t('Revenue') }}</th>
                        <th class="text-right">{{ $t('MinusMainCost') }}</th>
                        <th class="text-right">{{ $t('MinusExpenses') }}</th>
                        <th class="text-right">{{ $t('EqualsNet') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="c in b.counters" :key="c.counter_id">
                        <td class="text-left text-weight-medium">{{ c.counter }}</td>
                        <td class="text-right">{{ c.orders }}</td>
                        <td class="text-right">{{ money(c.revenue) }}</td>
                        <td class="text-right text-amber-9">− {{ money(c.cogs) }}</td>
                        <td class="text-right text-deep-orange">− {{ money(c.expenses) }}</td>
                        <td class="text-right">
                          <span class="mc-net" :class="c.net_profit >= 0 ? 'mc-net--up' : 'mc-net--down'">{{ money(c.net_profit) }}</span>
                        </td>
                      </tr>
                      <tr v-if="!b.counters.length"><td colspan="6" class="text-center text-grey-6">{{ $t('NoRecordFound') }}</td></tr>
                    </tbody>
                  </q-markup-table>
                </q-expansion-item>
              </q-list>
            </q-card>
          </div>
        </template>

        <!-- ═══════════ MAIN PRICE — where the real cost is set ═══════════ -->
        <template v-if="tab === 'prices'">
          <div class="col-12 q-mt-sm">
            <div class="row q-col-gutter-md">
              <div class="col-6 col-sm-3"><stat-card dense icon="mdi-diamond-stone" :label="$t('PricedProducts')" :value="(stats.priced ?? 0) + ' / ' + (stats.total ?? 0)" color="#B45309" tint="#FEF3C7" /></div>
              <div class="col-6 col-sm-3"><stat-card dense icon="savings" :label="$t('RealStockValue')" :value="money(stats.real_stock_value)" color="#175A8C" tint="#E0EDF7" /></div>
              <div class="col-6 col-sm-3"><stat-card dense icon="inventory_2" :label="$t('Operational')" :value="money(stats.declared_stock_value)" color="#0D9488" tint="#CCFBF1" /></div>
              <div class="col-6 col-sm-3"><stat-card dense icon="visibility_off" :label="$t('HiddenStockValue')" :value="money(stats.hidden_stock_value)" color="#7C3AED" tint="#EDE9FE" /></div>
            </div>
          </div>

          <div class="col-12 q-mt-sm">
            <div class="row q-col-gutter-sm items-center q-pa-sm bg-blue-grey-1 my_radio_less" style="border-radius:10px">
              <div class="col-12 col-sm-4">
                <q-input outlined dense bg-color="white" v-model="prf.search" :label="$t('Search')" clearable debounce="350" @update:model-value="loadProducts">
                  <template #prepend><q-icon name="search" color="amber-9" /></template>
                </q-input>
              </div>
              <div class="col-6 col-sm-3">
                <q-select outlined dense bg-color="white" v-model="prf.category_id" :options="categoryOptions" :label="$t('Category')" emit-value map-options clearable @update:model-value="loadProducts">
                  <template #prepend><q-icon name="category" color="amber-9" /></template>
                </q-select>
              </div>
              <div class="col-12 col-sm-3 flex items-center">
                <q-toggle v-model="prf.only_missing" :label="$t('OnlyMissingMainPrice')" color="amber-9" @update:model-value="loadProducts" />
              </div>
              <div class="col-12 col-sm-2 flex items-center justify-end">
                <q-chip v-if="!canEdit" dense square color="blue-grey-1" text-color="blue-grey-8" icon="visibility">{{ $t('ReadOnly') }}</q-chip>
              </div>
            </div>
          </div>

          <action-bar :rows="products" :columns="priceColumns" filename="main-cost" @update:filtered="() => {}" />

          <div class="col-12">
            <n-table :loading="loadingProducts" :data="products" :columns="priceColumns" v-model:filter="filter"
              :noEdit="true" :noDelete="true" :noInfo="true">
              <template v-slot:body-cell-name="props">
                <q-td :props="props">
                  <div class="text-weight-medium prod-link" @click.stop="$router.push('/products/' + props.row.id)">{{ props.row.name }}</div>
                  <div class="text-caption text-grey-6">{{ props.row.sku || '—' }}</div>
                </q-td>
              </template>
              <template v-slot:body-cell-sale_price="props">
                <q-td :props="props" class="text-weight-bold">{{ money(props.row.sale_price) }}</q-td>
              </template>
              <template v-slot:body-cell-cost_price="props">
                <q-td :props="props">{{ money(props.row.cost_price) }}</q-td>
              </template>
              <template v-slot:body-cell-main_price="props">
                <q-td :props="props">
                  <q-input outlined dense type="number" step="0.01" min="0"
                    v-model.number="drafts[props.row.id]" class="mc-price-input"
                    :class="{ 'mc-price-input--dirty': isDirty(props.row) }"
                    :placeholder="String(props.row.cost_price)" :readonly="!canEdit"
                    :loading="savingId === props.row.id"
                    @keyup.enter="saveMain(props.row)" @blur="saveMain(props.row)" @click.stop>
                    <template #prepend><q-icon name="mdi-diamond-stone" size="13px" color="amber-9" /></template>
                    <template #append>
                      <q-badge v-if="isFallback(props.row)" color="blue-grey-2" text-color="blue-grey-8" class="mc-auto">{{ $t('Auto') }}</q-badge>
                    </template>
                  </q-input>
                </q-td>
              </template>
              <template v-slot:body-cell-margin="props">
                <q-td :props="props">
                  <span class="mc-margin" :class="marginClass(margin(props.row))">{{ margin(props.row) }}%</span>
                </q-td>
              </template>
            </n-table>
          </div>
        </template>

        <!-- ═══════════ HISTORY ═══════════ -->
        <div v-if="tab === 'history'" class="col-12 q-mt-sm bg-white my_radio_less q-pa-md" style="border-radius:12px">
          <div v-if="loadingHistory" class="q-py-xl flex flex-center"><q-spinner color="amber-9" size="32px" /></div>
          <div v-else-if="!history.length" class="q-py-xl text-center text-grey-6">{{ $t('NoRecordFound') }}</div>
          <div v-else class="mc-timeline">
            <div v-for="h in history" :key="h.id" class="mc-tl-item">
              <div class="mc-tl-dot"></div>
              <div>
                <div class="mc-tl-line"><b>{{ h.user?.name || '—' }}</b><span class="text-grey-6"> · {{ h.product?.name || ('#' + h.product_id) }}</span></div>
                <div class="mc-tl-prices">
                  <span class="mc-tl-old">{{ h.old_price !== null ? money(h.old_price) : $t('NotSet') }}</span>
                  <q-icon name="arrow_forward" size="13px" class="q-mx-xs text-amber-9" />
                  <span class="mc-tl-new">{{ money(h.new_price) }}</span>
                </div>
                <div class="mc-tl-when">{{ h.created_at ? h.created_at.replace('T', ' ').slice(0, 16) : '' }}</div>
              </div>
            </div>
          </div>
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
const tab = ref('profit')

const money = (v) => (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })

// ── فایده خالص ────────────────────────────────────────────
const PERIODS = [
  { label: 'Today', value: 'daily' },
  { label: 'ThisMonth', value: 'monthly' },
  { label: 'ThisYear', value: 'yearly' },
  { label: 'Lifetime', value: 'lifetime' },
  { label: 'Period', value: 'custom' },
]
const data = ref({})
const loading = ref(false)
const pf = reactive({ period: 'daily', branch_id: null, counter_id: null, from: '', to: '' })

const totals = computed(() => data.value.totals || {})
const net = computed(() => Number(totals.value.net_profit || 0))

const branchOptions = computed(() =>
  (data.value.branch_options || []).map(b => ({ label: b.name, value: b.id })))

/** Counters narrow to the chosen branch — that is the drilldown the owner asked for. */
const counterOptions = computed(() => {
  const list = data.value.counter_options || []
  return list
    .filter(c => !pf.branch_id || c.branch_id === pf.branch_id)
    .map(c => ({ label: c.name, value: c.id }))
})

const scopeLabel = computed(() => {
  const c = (data.value.counter_options || []).find(x => x.id === pf.counter_id)
  if (c) return c.name
  const b = (data.value.branch_options || []).find(x => x.id === pf.branch_id)
  if (b) return b.name
  return proxy.$t('AllBranches2')
})

const trendMax = computed(() =>
  Math.max(1, ...(data.value.trend || []).map(b => Math.abs(Number(b.net_profit) || 0))))
const trendPct = (b) => Math.max(3, Math.round(Math.abs(Number(b.net_profit) || 0) / trendMax.value * 100))

function onPeriod () {
  if (pf.period === 'custom' && !pf.from) {
    pf.from = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10)
    pf.to = new Date().toISOString().slice(0, 10)
  }
  load()
}
/** Switching branch drops a counter that no longer belongs to it. */
function onBranch () {
  const still = (data.value.counter_options || [])
    .find(c => c.id === pf.counter_id && (!pf.branch_id || c.branch_id === pf.branch_id))
  if (!still) pf.counter_id = null
  load()
}

async function load () {
  loading.value = true
  try {
    const params = { period: pf.period }
    if (pf.period === 'custom') { params.from = pf.from; params.to = pf.to }
    if (pf.branch_id) params.branch_id = pf.branch_id
    if (pf.counter_id) params.counter_id = pf.counter_id
    const { data: d } = await api.get('/owner/net-profit', { params })
    data.value = d
  } catch (_) { data.value = {} } finally { loading.value = false }
}

// ── Export: every counter of every branch, as shown ──
const exportColumns = [
  { name: 'branch', label: 'Branch', field: 'branch' },
  { name: 'counter', label: 'Counter', field: 'counter' },
  { name: 'orders', label: 'Orders', field: 'orders' },
  { name: 'revenue', label: 'Revenue', field: 'revenue' },
  { name: 'cogs', label: 'MainCost', field: 'cogs' },
  { name: 'expenses', label: 'Expenses', field: 'expenses' },
  { name: 'net_profit', label: 'NetProfit', field: 'net_profit' },
  { name: 'margin', label: 'Margin', field: 'margin' },
]
const exportRows = computed(() => {
  const out = []
  for (const b of data.value.branches || []) {
    for (const c of b.counters || []) {
      out.push({ branch: b.branch, counter: c.counter, orders: c.orders, revenue: c.revenue, cogs: c.cogs, expenses: c.expenses, net_profit: c.net_profit, margin: c.margin })
    }
    out.push({ branch: b.branch, counter: '— ' + proxy.$t('Total'), orders: b.orders, revenue: b.revenue, cogs: b.cogs, expenses: b.expenses, net_profit: b.net_profit, margin: b.margin })
  }
  return out
})

// ── Main price ────────────────────────────────────────────
const products = ref([])
const stats = ref({})
const drafts = reactive({})
const loadingProducts = ref(false)
const savingId = ref(null)
const canEdit = ref(true)
const filter = ref('')
const categoryOptions = ref([])
const prf = reactive({ search: '', category_id: null, only_missing: false })

const priceColumns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'name', label: 'Name', field: 'name', align: 'left', sortable: true },
  { name: 'sale_price', label: 'SalePrice', field: 'sale_price', align: 'left', sortable: true },
  { name: 'cost_price', label: 'CostPrice', field: 'cost_price', align: 'left', sortable: true },
  { name: 'main_price', label: 'MainPrice', field: 'main_price', align: 'left', sortable: true },
  { name: 'margin', label: 'Margin', field: row => margin(row), align: 'left' },
]

function effective (p) {
  const d = drafts[p.id]
  if (d !== null && d !== undefined && d !== '') return Number(d)
  return Number(p.cost_price)
}
function isFallback (p) { return drafts[p.id] === null || drafts[p.id] === undefined || drafts[p.id] === '' }
function margin (p) {
  const s = Number(p.sale_price)
  return s > 0 ? Number(((s - effective(p)) / s * 100).toFixed(1)) : 0
}
function marginClass (m) { return m >= 30 ? 'mc-margin--high' : m >= 12 ? 'mc-margin--mid' : 'mc-margin--low' }
function isDirty (p) {
  const d = drafts[p.id]
  if (d === null || d === undefined || d === '') return p.main_price !== null
  return Number(d) !== Number(p.main_price)
}

async function loadProducts () {
  loadingProducts.value = true
  try {
    const params = { page: 1, per_page: 100000 }
    if (prf.search) params.search = prf.search
    if (prf.category_id) params.category_id = prf.category_id
    if (prf.only_missing) params.only_missing = 1
    const { data: d } = await api.get('/owner/main-cost/products', { params })
    products.value = d.products
    stats.value = d.stats
    canEdit.value = !!d.can_edit
    for (const p of d.products) drafts[p.id] = p.main_price
  } finally { loadingProducts.value = false }
}

async function saveMain (p) {
  if (!canEdit.value || !isDirty(p) || savingId.value === p.id) return
  const val = drafts[p.id] === '' || drafts[p.id] === undefined ? null : drafts[p.id]
  savingId.value = p.id
  try {
    const { data: d } = await api.put(`/owner/main-cost/products/${p.id}`, { main_price: val })
    p.main_price = d.main_price
    drafts[p.id] = d.main_price
    // The real cost changed, so the profit figure did too.
    load()
    loadStats()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { savingId.value = null }
}

async function loadStats () {
  try { const { data: d } = await api.get('/owner/main-cost/products', { params: { per_page: 5 } }); stats.value = d.stats } catch (_) {}
}

// ── History ───────────────────────────────────────────────
const history = ref([])
const loadingHistory = ref(false)
async function loadHistory () {
  loadingHistory.value = true
  try { const { data: d } = await api.get('/owner/main-cost/history'); history.value = d } finally { loadingHistory.value = false }
}

async function loadMeta () {
  try {
    const { data: d } = await api.get('/product-categories')
    categoryOptions.value = (d || []).map(c => ({ label: c.name, value: c.id }))
  } catch (_) {}
}

onMounted(() => { load(); loadProducts(); loadHistory(); loadMeta() })
</script>

<style scoped>
.mc-page { background: #F4F6FA; }
.mc-tabs { background: #fff; border-radius: 12px; }

/* Filters on one line — when, where, which till. */
.mc-filters {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  background: #fff; border: 1px solid #E9EDF3; border-radius: 12px; padding: 10px 12px;
}
.mc-periods { border: 1px solid #E9EDF3; border-radius: 10px; overflow: hidden; }
.mc-filter { min-width: 168px; }

/* ══ The headline: deep navy, gold rail, one enormous number ══ */
.mc-hero {
  position: relative; display: flex; align-items: stretch; gap: 26px; flex-wrap: wrap;
  background: linear-gradient(118deg, #0E2A47 0%, #123A66 52%, #17517F 100%);
  border-radius: 18px; padding: 22px 26px; color: #fff; overflow: hidden;
  box-shadow: 0 26px 46px -28px rgba(14, 42, 71, 0.92);
}
.mc-hero::before {
  content: ''; position: absolute; width: 380px; height: 380px; border-radius: 50%;
  background: radial-gradient(circle, rgba(243, 212, 139, 0.16), transparent 65%);
  top: -170px; inset-inline-end: -90px; pointer-events: none;
}
.mc-hero::after {
  content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 3px;
  background: linear-gradient(90deg, transparent, #C8862D 30%, #F3D48B 50%, #C8862D 70%, transparent);
}
.mc-hero--loss::after { background: linear-gradient(90deg, transparent, #B91C1C 35%, #FCA5A5 50%, #B91C1C 65%, transparent); }
.mc-hero__main { flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.mc-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10.5px; font-weight: 800; letter-spacing: 2.2px; text-transform: uppercase; color: #F3D48B;
}
.mc-hero__value {
  font-size: 46px; font-weight: 900; letter-spacing: -1.4px; line-height: 1.05;
  margin-top: 4px; font-variant-numeric: tabular-nums;
}
.mc-hero--loss .mc-hero__value { color: #FCA5A5; }
.mc-hero__value small { font-size: 17px; font-weight: 700; color: #9FC1E0; }
.mc-hero__scope { font-size: 11.5px; color: #9FC1E0; margin-top: 5px; }
.mc-hero__skeleton {
  display: inline-block; width: 220px; height: 40px; border-radius: 10px;
  background: rgba(255, 255, 255, 0.12); animation: mcpulse 1.1s ease-in-out infinite;
}
@keyframes mcpulse { 50% { opacity: 0.45; } }

/* The subtraction, laid out so the number can be checked at a glance. */
.mc-calc { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex: 1 1 420px; }
.mc-calc__item {
  background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 11px; padding: 8px 12px; min-width: 104px;
}
.mc-calc__item span { display: block; font-size: 9.5px; color: #9FC1E0; text-transform: uppercase; letter-spacing: 0.8px; }
.mc-calc__item b { display: block; font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums; margin-top: 2px; }
.mc-calc__item--net { border-color: rgba(243, 212, 139, 0.55); background: rgba(243, 212, 139, 0.12); }
.mc-calc__item--net b { color: #F3D48B; }
.mc-calc__op { font-size: 17px; font-weight: 800; color: #6C93BC; }
.mc-calc__op--eq { color: #F3D48B; }

.mc-hero__side { display: flex; flex-direction: column; justify-content: center; gap: 12px; min-width: 96px; }
.mc-kpi b { display: block; font-size: 20px; font-weight: 900; font-variant-numeric: tabular-nums; }
.mc-kpi span { font-size: 10px; color: #9FC1E0; }
.mc-hint { font-size: 11px; color: #94A3B8; margin-top: 6px; padding-inline-start: 4px; }

.mc-card { border-radius: 14px; }
.mc-card__title { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; }

/* Trend */
.mc-trend { display: flex; align-items: flex-end; gap: 5px; height: 150px; }
.mc-trend__col { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.mc-trend__bar { width: 100%; max-width: 34px; min-height: 3px; border-radius: 6px 6px 0 0;
  background: linear-gradient(180deg, #F3D48B, #C8862D); transition: height .35s ease; }
.mc-trend__bar--loss { background: linear-gradient(180deg, #FCA5A5, #B91C1C); }
.mc-trend__col span { margin-top: 4px; font-size: 9px; color: #94A3B8; white-space: nowrap; }

/* Branch rows and their counters */
.mc-net { font-size: 14px; font-weight: 800; font-variant-numeric: tabular-nums; }
.mc-net--up { color: #15803D; }
.mc-net--down { color: #B91C1C; }
.mc-sub { background: #FBFCFE; }
.mc-sub th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748B; font-weight: 800; }
.mc-sub td { font-size: 12.5px; font-variant-numeric: tabular-nums; }

/* Main price editing */
.mc-price-input { min-width: 148px; max-width: 176px; }
.mc-price-input :deep(.q-field__control) { border-radius: 10px; background: #fff; }
.mc-price-input--dirty :deep(.q-field__control):before { border-color: #B45309; }
.mc-auto { font-size: 9.5px; font-weight: 700; }
.mc-margin { display: inline-block; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 8px; }
.mc-margin--high { background: #ECFDF5; color: #047857; }
.mc-margin--mid { background: #FFFBEB; color: #B45309; }
.mc-margin--low { background: #FEF2F2; color: #B91C1C; }
.prod-link { color: var(--q-primary); cursor: pointer; }
.prod-link:hover { text-decoration: underline; }

/* History */
.mc-timeline { position: relative; padding-inline-start: 18px; }
.mc-timeline::before { content: ''; position: absolute; inset-block: 6px; inset-inline-start: 5px; width: 2px; background: #E9EDF3; }
.mc-tl-item { position: relative; display: flex; gap: 12px; padding: 8px 0; }
.mc-tl-dot { position: absolute; inset-inline-start: -18px; top: 14px; width: 12px; height: 12px; border-radius: 50%; background: #F3D48B; border: 2.5px solid #B45309; }
.mc-tl-line { font-size: 12.5px; color: #0F172A; }
.mc-tl-prices { display: flex; align-items: center; font-size: 12.5px; margin-top: 2px; }
.mc-tl-old { color: #94A3B8; text-decoration: line-through; }
.mc-tl-new { font-weight: 800; color: #B45309; }
.mc-tl-when { font-size: 10.5px; color: #94A3B8; margin-top: 2px; }

@media (max-width: 720px) {
  .mc-hero { padding: 18px; gap: 16px; }
  .mc-hero__value { font-size: 34px; }
  .mc-calc__item { min-width: 88px; padding: 6px 9px; }
}
</style>
