<template>
  <q-page class="q-pa-md rep-page">
    <div class="row items-center q-mb-md">
      <div>
        <div class="rep-title">{{ $t('SalesReports') }}</div>
        <div class="text-caption text-grey-6">{{ range.from }} → {{ range.to }}</div>
      </div>
      <q-space />
      <div class="row q-gutter-sm items-center">
        <q-input outlined dense type="date" v-model="from" :label="$t('From')" style="width:150px" />
        <q-input outlined dense type="date" v-model="to" :label="$t('To')" style="width:150px" />
        <q-btn unelevated no-caps color="primary" icon="refresh" :label="$t('Apply')" @click="loadAll" />
      </div>
    </div>

    <!-- quick range chips -->
    <div class="row q-gutter-xs q-mb-md">
      <q-chip v-for="r in quickRanges" :key="r.label" clickable dense
        :color="activeRange === r.label ? 'primary' : 'grey-2'"
        :text-color="activeRange === r.label ? 'white' : 'grey-8'"
        @click="applyQuick(r)">{{ $t(r.label) }}</q-chip>
    </div>

    <!-- KPI cards -->
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-6 col-md-3"><stat-card icon="payments" :label="$t('Revenue')" :value="money(sales.totals?.gross)" color="#16A34A" tint="#DCFCE7" :sub="$t('NetOfReturns')" sub-icon="trending_up" /></div>
      <div class="col-6 col-md-3"><stat-card icon="savings" :label="$t('Profit')" :value="money(sales.totals?.profit)" color="#175A8C" tint="#E0EDF7" :sub="(sales.totals?.margin ?? 0) + '% ' + $t('Margin')" sub-icon="percent" /></div>
      <div class="col-6 col-md-3"><stat-card icon="receipt_long" :label="$t('Orders')" :value="sales.totals?.orders ?? 0" color="#7C3AED" tint="#EDE9FE" :sub="$t('Transactions')" sub-icon="shopping_bag" /></div>
      <div class="col-6 col-md-3"><stat-card icon="shopping_cart" :label="$t('AvgBasket')" :value="money(sales.totals?.avg_basket)" color="#D97706" tint="#FEF3C7" :sub="$t('PerOrder')" sub-icon="local_mall" /></div>
    </div>

    <div class="row q-col-gutter-md">
      <!-- Daily revenue trend (single-measure bars, one hue) -->
      <div class="col-12 col-lg-8">
        <q-card flat bordered class="rep-card">
          <div class="rep-card__head"><q-icon name="bar_chart" size="18px" class="q-mr-xs" />{{ $t('DailyRevenue') }}</div>
          <div v-if="trendMax === 0" class="rep-empty">{{ $t('NoDataInRange') }}</div>
          <svg v-else class="rep-bars" :viewBox="`0 0 ${trendW} ${trendH}`" preserveAspectRatio="none" role="img">
            <g v-for="(d, i) in sales.trend" :key="d.date">
              <rect class="rep-bar" :x="barX(i)" :y="barY(d.revenue)" :width="barW" :height="barHeight(d.revenue)" rx="3">
                <title>{{ d.date }} · {{ money(d.revenue) }} · {{ d.orders }} {{ $t('Orders') }}</title>
              </rect>
            </g>
          </svg>
          <div v-if="trendMax > 0" class="rep-axis"><span>{{ sales.trend[0]?.date?.slice(5) }}</span><span>{{ money(trendMax) }} {{ $t('peak') }}</span><span>{{ sales.trend[sales.trend.length-1]?.date?.slice(5) }}</span></div>
        </q-card>
      </div>

      <!-- Payment mix (categorical, validated 3-colour) -->
      <div class="col-12 col-lg-4">
        <q-card flat bordered class="rep-card">
          <div class="rep-card__head"><q-icon name="account_balance_wallet" size="18px" class="q-mr-xs" />{{ $t('PaymentMix') }}</div>
          <div v-if="!(sales.by_payment || []).length" class="rep-empty">{{ $t('NoDataInRange') }}</div>
          <div v-else class="q-pa-sm">
            <div v-for="p in sales.by_payment" :key="p.method" class="rep-pay">
              <div class="row items-center q-mb-xs">
                <span class="rep-dot" :style="`background:${payColor(p.method)}`"></span>
                <span class="text-weight-medium">{{ $t(payLabel(p.method)) }}</span>
                <q-space />
                <span class="text-weight-bold">{{ money(p.amount) }}</span>
              </div>
              <div class="rep-track"><div class="rep-fill" :style="`width:${payPct(p.amount)}%;background:${payColor(p.method)}`"></div></div>
            </div>
          </div>
        </q-card>
      </div>

      <!-- Sales by category (single hue, direct-labelled) -->
      <div class="col-12 col-md-6">
        <q-card flat bordered class="rep-card">
          <div class="rep-card__head"><q-icon name="category" size="18px" class="q-mr-xs" />{{ $t('SalesByCategory') }}</div>
          <div v-if="!(sales.by_category || []).length" class="rep-empty">{{ $t('NoDataInRange') }}</div>
          <div v-else class="q-pa-sm">
            <div v-for="c in sales.by_category" :key="c.name" class="rep-hbar">
              <div class="row items-center"><span class="rep-hbar__label">{{ c.name }}</span><q-space /><span class="text-weight-bold">{{ money(c.revenue) }}</span></div>
              <div class="rep-track"><div class="rep-fill rep-fill--blue" :style="`width:${catPct(c.revenue)}%`"></div></div>
            </div>
          </div>
        </q-card>
      </div>

      <!-- Top products (table) -->
      <div class="col-12 col-md-6">
        <q-card flat bordered class="rep-card">
          <div class="rep-card__head"><q-icon name="leaderboard" size="18px" class="q-mr-xs" />{{ $t('TopProducts') }}</div>
          <q-markup-table flat dense class="rep-table">
            <thead><tr><th class="text-left">{{ $t('Product') }}</th><th class="text-right">{{ $t('Qty') }}</th><th class="text-right">{{ $t('Revenue') }}</th><th class="text-right">{{ $t('Profit') }}</th></tr></thead>
            <tbody>
              <tr v-if="!(sales.top_products || []).length"><td colspan="4" class="text-center text-grey-5">{{ $t('NoDataInRange') }}</td></tr>
              <tr v-for="p in sales.top_products" :key="p.name">
                <td class="text-left">{{ p.name }}</td>
                <td class="text-right">{{ Number(p.qty) }}</td>
                <td class="text-right">{{ money(p.revenue) }}</td>
                <td class="text-right text-positive">{{ money(p.profit) }}</td>
              </tr>
            </tbody>
          </q-markup-table>
        </q-card>
      </div>
    </div>

    <!-- Inventory section -->
    <div class="rep-section">{{ $t('InventoryHealth') }}</div>
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-6 col-md-3"><stat-card icon="inventory_2" :label="$t('StockValueCost')" :value="money(inv.stock_value)" color="#175A8C" tint="#E0EDF7" /></div>
      <div class="col-6 col-md-3"><stat-card icon="sell" :label="$t('StockValueRetail')" :value="money(inv.retail_value)" color="#16A34A" tint="#DCFCE7" /></div>
      <div class="col-6 col-md-3"><stat-card icon="trending_up" :label="$t('PotentialMargin')" :value="money(inv.potential_margin)" color="#7C3AED" tint="#EDE9FE" /></div>
      <div class="col-6 col-md-3"><stat-card icon="warning" :label="$t('OutOfStock')" :value="inv.out_of_stock ?? 0" color="#DC2626" tint="#FEE2E2" /></div>
    </div>

    <div class="row q-col-gutter-md">
      <div class="col-12 col-md-6">
        <q-card flat bordered class="rep-card">
          <div class="rep-card__head"><q-icon name="pie_chart" size="18px" class="q-mr-xs" />{{ $t('StockValueByCategory') }}</div>
          <div v-if="!(inv.by_category || []).length" class="rep-empty">{{ $t('NoData') }}</div>
          <div v-else class="q-pa-sm">
            <div v-for="c in inv.by_category" :key="c.name" class="rep-hbar">
              <div class="row items-center"><span class="rep-hbar__label">{{ c.name }}</span><q-space /><span class="text-grey-7">{{ c.items }} · </span><span class="text-weight-bold q-ml-xs">{{ money(c.value) }}</span></div>
              <div class="rep-track"><div class="rep-fill rep-fill--blue" :style="`width:${invCatPct(c.value)}%`"></div></div>
            </div>
          </div>
        </q-card>
      </div>
      <div class="col-12 col-md-6">
        <q-card flat bordered class="rep-card">
          <div class="rep-card__head"><q-icon name="production_quantity_limits" size="18px" class="q-mr-xs" />{{ $t('LowStockAlerts') }}</div>
          <q-markup-table flat dense class="rep-table">
            <thead><tr><th class="text-left">{{ $t('Product') }}</th><th class="text-right">{{ $t('StockQty') }}</th><th class="text-right">{{ $t('MinStock') }}</th></tr></thead>
            <tbody>
              <tr v-if="!(inv.low_stock || []).length"><td colspan="3" class="text-center text-grey-5">{{ $t('AllStockHealthy') }}</td></tr>
              <tr v-for="p in inv.low_stock" :key="p.id">
                <td class="text-left">{{ p.name }}</td>
                <td class="text-right"><q-badge :color="Number(p.stock_qty) <= 0 ? 'negative' : 'orange'">{{ Number(p.stock_qty) }}</q-badge></td>
                <td class="text-right text-grey-7">{{ Number(p.min_stock) }}</td>
              </tr>
            </tbody>
          </q-markup-table>
        </q-card>
      </div>
    </div>
    <!-- ── The period report: the SAME report the owner's desk shows,
             costed at the normal price here and at the Main Price there. ── -->
    <q-card flat bordered class="rep-card q-mt-md">
      <div class="rep-card__head">
        <q-icon name="insights" size="18px" class="q-mr-xs" />{{ $t('ProfitByPeriodAndPerson') }}
      </div>
      <div class="q-pa-md">
        <period-report
          :filters="pf" :summary="period.summary || {}"
          :periods="period.periods || []" :by-user="period.by_user || []"
          :with-main-cost="!!period.with_main_cost" :loading="loadingPeriod"
          @change="onPeriodChange" />
      </div>
    </q-card>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { api } from '@/boot/axios'
import PeriodReport from '@/components/reports/PeriodReport.vue'

const { proxy } = getCurrentInstance()
const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' AFN'

const today = new Date()
const iso = (d) => d.toISOString().slice(0, 10)
const from = ref(iso(new Date(today.getTime() - 29 * 864e5)))
const to = ref(iso(today))
const activeRange = ref('Last30Days')

const sales = ref({})
const inv = ref({})

// The shared period report. Its own date range, so drilling into a month here
// does not disturb the KPI cards above.
const pf = reactive({ granularity: 'monthly', from: '', to: '', user_id: null })
const period = ref({})
const loadingPeriod = ref(false)

async function loadPeriod () {
  loadingPeriod.value = true
  try {
    const { data } = await api.get('/reports/period', { params: { ...pf } })
    period.value = data
  } catch (_) { period.value = {} } finally { loadingPeriod.value = false }
}
function onPeriodChange (patch) {
  Object.assign(pf, patch)
  loadPeriod()
}
const range = computed(() => sales.value.range || { from: from.value, to: to.value })

const quickRanges = [
  { label: 'Today', days: 0 },
  { label: 'Last7Days', days: 6 },
  { label: 'Last30Days', days: 29 },
  { label: 'Last90Days', days: 89 },
]
function applyQuick (r) {
  activeRange.value = r.label
  to.value = iso(today)
  from.value = iso(new Date(today.getTime() - r.days * 864e5))
  loadAll()
}

async function loadAll () {
  try { const { data } = await api.get('/reports/sales', { params: { from: from.value, to: to.value } }); sales.value = data } catch (_) {}
  try { const { data } = await api.get('/reports/inventory'); inv.value = data } catch (_) {}
}
onMounted(() => { loadAll(); loadPeriod() })

// ── daily bars geometry ──
const trendH = 150
const trendW = computed(() => Math.max(300, (sales.value.trend || []).length * 14))
const trendMax = computed(() => Math.max(0, ...((sales.value.trend || []).map(d => Number(d.revenue)))))
const barW = computed(() => { const n = (sales.value.trend || []).length || 1; return (trendW.value / n) * 0.68 })
function barX (i) { const n = (sales.value.trend || []).length || 1; return (trendW.value / n) * i + (trendW.value / n) * 0.16 }
function barHeight (v) { return trendMax.value ? (Number(v) / trendMax.value) * (trendH - 10) : 0 }
function barY (v) { return trendH - barHeight(v) }

// ── payment mix ──
const PAY = { cash: { c: '#16A34A', l: 'Cash' }, card: { c: '#175A8C', l: 'Card' }, mobile: { c: '#7C3AED', l: 'Mobile' }, credit: { c: '#D97706', l: 'Credit' } }
function payColor (m) { return PAY[m]?.c || '#94A3B8' }
function payLabel (m) { return PAY[m]?.l || m }
const payTotal = computed(() => (sales.value.by_payment || []).reduce((s, p) => s + Number(p.amount), 0))
function payPct (v) { return payTotal.value ? (Number(v) / payTotal.value) * 100 : 0 }

// ── category bars ──
const catMax = computed(() => Math.max(0, ...((sales.value.by_category || []).map(c => Number(c.revenue)))))
function catPct (v) { return catMax.value ? (Number(v) / catMax.value) * 100 : 0 }
const invCatMax = computed(() => Math.max(0, ...((inv.value.by_category || []).map(c => Number(c.value)))))
function invCatPct (v) { return invCatMax.value ? (Number(v) / invCatMax.value) * 100 : 0 }
</script>

<style scoped>
.rep-page { background: #F0F4F8; }
.rep-title { font-size: 20px; font-weight: 800; color: #0F172A; }
.rep-section { font-size: 15px; font-weight: 800; color: #175A8C; margin: 22px 0 10px; }
.rep-card { border-radius: 16px; padding-bottom: 6px; }
.rep-card__head { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; padding: 12px 14px 6px; }
.rep-empty { color: #94A3B8; text-align: center; padding: 30px; font-size: 13px; }
.rep-bars { width: 100%; height: 150px; padding: 0 10px; }
.rep-bar { fill: #175A8C; transition: fill .15s ease; }
.rep-bar:hover { fill: #C8862D; }
.rep-axis { display: flex; justify-content: space-between; padding: 4px 14px 8px; font-size: 10.5px; color: #94A3B8; }
.rep-pay { margin-bottom: 12px; }
.rep-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-inline-end: 8px; }
.rep-track { height: 8px; border-radius: 5px; background: #EEF2F6; overflow: hidden; }
.rep-fill { height: 100%; border-radius: 5px; }
.rep-fill--blue { background: linear-gradient(90deg, #2E6DA4, #175A8C); }
.rep-hbar { margin-bottom: 10px; }
.rep-hbar__label { font-size: 12.5px; font-weight: 600; color: #334155; }
.rep-table th { font-weight: 700; color: #475569; font-size: 12px; }
</style>
