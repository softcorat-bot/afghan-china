<template>
  <q-page class="pd-page q-pa-md">
    <div v-if="loading" class="text-center q-pa-xl"><q-spinner size="48px" color="primary" /></div>

    <template v-else-if="product">
      <!-- header -->
      <div class="pd-head">
        <q-btn flat round dense icon="arrow_back" color="grey-7" @click="$router.back()" />
        <div class="pd-head__img">
          <img v-if="product.image_url" :src="assetUrl(product.image_url)" :alt="product.name">
          <q-icon v-else name="inventory_2" size="30px" color="blue-grey-4" />
        </div>
        <div class="col">
          <div class="pd-head__name">{{ product.name }}
            <q-chip dense size="sm" :color="statusColor(product.status)" text-color="white" class="q-ml-sm">{{ $t(statusKey(product.status)) }}</q-chip>
          </div>
          <div class="pd-head__meta">
            <span v-if="product.name_fa">{{ product.name_fa }} · </span>
            <span v-if="product.category?.name">{{ product.category.name }} · </span>
            <span v-if="product.sku">{{ product.sku }} · </span>
            <span v-if="product.barcode"><q-icon name="qr_code_2" size="13px" /> {{ product.barcode }}</span>
          </div>
        </div>
        <q-btn v-if="$can('product-edit')" outline no-caps color="primary" icon="edit" :label="$t('Edit')" to="/products" />
      </div>

      <!-- tabs: the overview, then one full ledger per movement type -->
      <q-tabs v-model="tab" dense no-caps align="left" class="pd-tabs"
        active-color="primary" indicator-color="primary" narrow-indicator>
        <q-tab name="overview" icon="dashboard" :label="$t('Overview')" />
        <q-tab name="sales" icon="receipt_long" :label="$t('SalesHistory')" />
        <q-tab name="purchases" icon="local_shipping" :label="$t('PurchaseHistory')" />
        <q-tab name="stock" icon="tune" :label="$t('StockHistory')" />
        <q-tab name="transfers" icon="swap_horiz" :label="$t('StockMovements')" />
        <q-tab v-if="canMainCost" name="price" icon="mdi-diamond-stone" :label="$t('MainPriceJournal')" />
      </q-tabs>

      <q-tab-panels v-model="tab" animated keep-alive class="pd-panels">
      <q-tab-panel name="overview" class="q-pa-none">

      <!-- KPI row -->
      <div class="row q-col-gutter-md q-mt-sm">
        <div class="col-6 col-md-3"><stat-card icon="inventory" :label="$t('CurrentStock')" :value="Number(product.stock_qty)" :color="product.low_stock ? '#DC2626' : '#16A34A'" :tint="product.low_stock ? '#FEE2E2' : '#DCFCE7'" :sub="$t('MinStock') + ': ' + Number(product.min_stock)" sub-icon="warning" /></div>
        <div class="col-6 col-md-3"><stat-card icon="sell" :label="$t('UnitsSold')" :value="stats.units_sold" color="#175A8C" tint="#E0EDF7" :sub="stats.units_refunded ? $t('Refunded') + ': ' + stats.units_refunded : ''" sub-icon="undo" /></div>
        <div class="col-6 col-md-3"><stat-card icon="payments" :label="$t('Revenue')" :value="money(stats.revenue)" color="#7C3AED" tint="#EDE9FE" /></div>
        <div class="col-6 col-md-3"><stat-card icon="trending_up" :label="$t('Profit')" :value="money(stats.profit)" color="#16A34A" tint="#DCFCE7" :sub="stats.margin + '% ' + $t('Margin')" sub-icon="percent" /></div>
      </div>

      <div class="row q-col-gutter-md q-mt-xs">
        <!-- pricing + stock value -->
        <div class="col-12 col-md-4">
          <q-card flat bordered class="pd-card">
            <div class="pd-card__head"><q-icon name="attach_money" size="18px" class="q-mr-xs" />{{ $t('PricingAndValue') }}</div>
            <div class="q-pa-md">
              <div class="pd-row"><span>{{ $t('CostPrice') }}</span><b>{{ money(product.cost_price) }}</b></div>
              <div class="pd-row"><span>{{ $t('SalePrice') }}</span><b class="text-primary">{{ money(product.sale_price) }}</b></div>
              <div class="pd-row" v-if="product.compare_at_price"><span>{{ $t('CompareAtPrice') }}</span><span class="text-strike text-grey-6">{{ money(product.compare_at_price) }}</span></div>
              <div class="pd-row"><span>{{ $t('TaxRate') }}</span><span>{{ Number(product.tax_rate) }}%</span></div>
              <div class="pd-row"><span>{{ $t('Margin') }}</span><b>{{ product.margin }}%</b></div>
              <q-separator class="q-my-sm" />
              <div class="pd-row"><span>{{ $t('StockValueCost') }}</span><b>{{ money(stats.stock_value_cost) }}</b></div>
              <div class="pd-row"><span>{{ $t('StockValueRetail') }}</span><b>{{ money(stats.stock_value_retail) }}</b></div>
              <q-separator class="q-my-sm" />
              <div class="pd-row"><span>{{ $t('UnitsReceived') }}</span><span>{{ stats.units_received }}</span></div>
              <div class="pd-row"><span>{{ $t('PurchaseCost') }}</span><span>{{ money(stats.purchase_cost) }}</span></div>
            </div>
          </q-card>
        </div>

        <!-- 30-day trend -->
        <div class="col-12 col-md-8">
          <q-card flat bordered class="pd-card">
            <div class="pd-card__head"><q-icon name="bar_chart" size="18px" class="q-mr-xs" />{{ $t('SalesLast30Days') }}</div>
            <div v-if="trendMax === 0" class="pd-empty">{{ $t('NoSalesInRange') }}</div>
            <svg v-else class="pd-bars" :viewBox="`0 0 ${trendW} 140`" preserveAspectRatio="none">
              <g v-for="(d, i) in trend" :key="d.date">
                <rect class="pd-bar" :x="barX(i)" :y="barY(d.qty)" :width="barW" :height="barH(d.qty)" rx="2">
                  <title>{{ d.date }} · {{ d.qty }} {{ $t('Sold') }} · {{ money(d.revenue) }}</title>
                </rect>
              </g>
            </svg>
            <div v-if="trendMax > 0" class="pd-axis"><span>{{ trend[0]?.date?.slice(5) }}</span><span>{{ trendMax }} {{ $t('peak') }}</span><span>{{ trend[trend.length-1]?.date?.slice(5) }}</span></div>
          </q-card>
        </div>
      </div>

      <!-- history -->
      <div class="row q-col-gutter-md q-mt-xs">
        <div class="col-12 col-md-4">
          <q-card flat bordered class="pd-card">
            <div class="pd-card__head"><q-icon name="receipt_long" size="18px" class="q-mr-xs" />{{ $t('RecentSales') }}</div>
            <q-scroll-area style="height:260px">
              <div v-if="!recentSales.length" class="pd-empty">{{ $t('NoRecordFound') }}</div>
              <div v-for="(s, i) in recentSales" :key="i" class="pd-hist">
                <div class="col">
                  <div class="text-weight-medium">{{ s.invoice_no }}</div>
                  <div class="text-caption text-grey-5">{{ fmtDate(s.date) }}<span v-if="s.refunded_qty > 0" class="text-orange-8"> · {{ $t('Refunded') }} {{ s.refunded_qty }}</span></div>
                </div>
                <div class="text-right">
                  <div class="text-weight-bold">{{ s.qty }} × {{ money(s.unit_price) }}</div>
                  <div class="text-caption text-grey-6">{{ money(s.line_total) }}</div>
                </div>
              </div>
            </q-scroll-area>
          </q-card>
        </div>
        <div class="col-12 col-md-4">
          <q-card flat bordered class="pd-card">
            <div class="pd-card__head"><q-icon name="local_shipping" size="18px" class="q-mr-xs" />{{ $t('RecentPurchases') }}</div>
            <q-scroll-area style="height:260px">
              <div v-if="!recentPurchases.length" class="pd-empty">{{ $t('NoRecordFound') }}</div>
              <div v-for="(pu, i) in recentPurchases" :key="i" class="pd-hist">
                <div class="col">
                  <div class="text-weight-medium">{{ pu.reference }}</div>
                  <div class="text-caption text-grey-5">{{ fmtDate(pu.date) }}<span v-if="pu.returned_qty > 0" class="text-negative"> · {{ $t('Returned') }} {{ pu.returned_qty }}</span></div>
                </div>
                <div class="text-right">
                  <div class="text-weight-bold text-positive">+{{ pu.qty }}</div>
                  <div class="text-caption text-grey-6">@ {{ money(pu.cost_price) }}</div>
                </div>
              </div>
            </q-scroll-area>
          </q-card>
        </div>
        <div class="col-12 col-md-4">
          <q-card flat bordered class="pd-card">
            <div class="pd-card__head"><q-icon name="tune" size="18px" class="q-mr-xs" />{{ $t('RecentAdjustments') }}</div>
            <q-scroll-area style="height:260px">
              <div v-if="!recentAdjustments.length" class="pd-empty">{{ $t('NoRecordFound') }}</div>
              <div v-for="(a, i) in recentAdjustments" :key="i" class="pd-hist">
                <q-icon :name="a.type === 'increase' ? 'arrow_upward' : 'arrow_downward'" :color="a.type === 'increase' ? 'positive' : 'negative'" size="16px" class="q-mr-xs" />
                <div class="col">
                  <div class="text-weight-medium">{{ a.type === 'increase' ? '+' : '−' }}{{ a.qty }} <span class="text-grey-6">({{ a.stock_before }} → {{ a.stock_after }})</span></div>
                  <div class="text-caption text-grey-5">{{ a.reason || '—' }} · {{ a.user || '—' }} · {{ fmtDate(a.date) }}</div>
                </div>
              </div>
            </q-scroll-area>
          </q-card>
        </div>
      </div>

      </q-tab-panel>

      <!-- ── full ledgers ────────────────────────────────────────── -->
      <q-tab-panel v-for="t in HISTORY_TABS" :key="t" :name="t" class="q-pa-none">
        <q-card flat bordered class="pd-card q-mt-sm">
          <div class="pd-card__head">
            <q-icon :name="TAB_ICON[t]" size="18px" class="q-mr-xs" />{{ $t(TAB_LABEL[t]) }}
          </div>
          <history-table
            :columns="COLUMNS[t]"
            :rows="hist[t].rows"
            :loading="hist[t].loading"
            :page="hist[t].page"
            :last-page="hist[t].lastPage"
            :total="hist[t].total"
            @page="p => loadHistory(t, p)">
            <template #cell-date="{ value }">{{ fmtDate(value) }}</template>
            <template #cell-type="{ value }">
              <q-chip dense size="sm" :color="value === 'increase' ? 'green-1' : 'red-1'"
                :text-color="value === 'increase' ? 'green-9' : 'red-9'">
                {{ value === 'increase' ? '+' : '−' }} {{ $t(value === 'increase' ? 'Increase' : 'Decrease') }}
              </q-chip>
            </template>
            <template #cell-direction="{ value }">
              <q-chip dense size="sm" :color="value === 'in' ? 'blue-1' : 'amber-1'"
                :text-color="value === 'in' ? 'blue-9' : 'amber-9'">
                {{ value === 'in' ? $t('Inbound') : $t('Outbound') }}
              </q-chip>
            </template>
          </history-table>
        </q-card>
      </q-tab-panel>
      </q-tab-panels>
    </template>
  </q-page>
</template>

<script setup>
import { ref, computed, reactive, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, assetUrl } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'
import HistoryTable from '@/components/tables/HistoryTable.vue'

const route = useRoute()
const auth = useAuthStore()
const canMainCost = computed(() => auth.canMainCost)
const loading = ref(true)
const product = ref(null)
const stats = ref({})
const trend = ref([])
const recentSales = ref([])
const recentPurchases = ref([])
const recentAdjustments = ref([])

const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' AFN'
const fmtDate = (v) => v ? new Date(v).toLocaleDateString() : '—'
const statusColor = (s) => s === 'active' ? 'green' : s === 'draft' ? 'grey' : 'orange'
const statusKey = (s) => s === 'active' ? 'Active' : s === 'draft' ? 'Draft' : 'Archived'

async function load () {
  loading.value = true
  try {
    const { data } = await api.get(`/products/${route.params.id}/dashboard`)
    product.value = data.product
    stats.value = data.stats || {}
    trend.value = data.trend || []
    recentSales.value = data.recent_sales || []
    recentPurchases.value = data.recent_purchases || []
    recentAdjustments.value = data.recent_adjustments || []
  } catch (_) { product.value = null } finally { loading.value = false }
}
onMounted(load)
watch(() => route.params.id, load)

// trend bars
const trendW = computed(() => Math.max(300, trend.value.length * 12))
const trendMax = computed(() => Math.max(0, ...trend.value.map(d => Number(d.qty))))
const barW = computed(() => { const n = trend.value.length || 1; return (trendW.value / n) * 0.66 })
const barX = (i) => { const n = trend.value.length || 1; return (trendW.value / n) * i + (trendW.value / n) * 0.17 }
const barH = (v) => trendMax.value ? (Number(v) / trendMax.value) * 126 : 0
const barY = (v) => 140 - barH(v)

// ── the full ledgers, one tab each ──────────────────────
const tab = ref('overview')
const HISTORY_TABS = ['sales', 'purchases', 'stock', 'transfers', 'price']
const TAB_ICON = {
  sales: 'receipt_long', purchases: 'local_shipping', stock: 'tune',
  transfers: 'swap_horiz', price: 'mdi-diamond-stone',
}
const TAB_LABEL = {
  sales: 'SalesHistory', purchases: 'PurchaseHistory', stock: 'StockHistory',
  transfers: 'StockMovements', price: 'MainPriceJournal',
}

const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 3 })

const COLUMNS = {
  sales: [
    { name: 'date', label: 'Date' },
    { name: 'reference', label: 'Invoice' },
    { name: 'party', label: 'Customer' },
    { name: 'user', label: 'Cashier' },
    { name: 'qty', label: 'Qty', align: 'right', format: num },
    { name: 'unit_price', label: 'UnitPrice', align: 'right', format: money },
    { name: 'discount', label: 'Discount', align: 'right', format: money },
    { name: 'total', label: 'Total', align: 'right', format: money },
    { name: 'refunded_qty', label: 'Refunded', align: 'right', format: (v) => Number(v) ? num(v) : '—' },
  ],
  purchases: [
    { name: 'date', label: 'Date' },
    { name: 'reference', label: 'Reference' },
    { name: 'party', label: 'Supplier' },
    { name: 'qty', label: 'Qty', align: 'right', format: num },
    { name: 'unit_price', label: 'CostPrice', align: 'right', format: money },
    { name: 'total', label: 'Total', align: 'right', format: money },
    { name: 'returned_qty', label: 'Returned', align: 'right', format: (v) => Number(v) ? num(v) : '—' },
  ],
  stock: [
    { name: 'date', label: 'Date' },
    { name: 'type', label: 'Type' },
    { name: 'qty', label: 'Qty', align: 'right', format: num },
    { name: 'stock_before', label: 'Before', align: 'right', format: num },
    { name: 'stock_after', label: 'After', align: 'right', format: num },
    { name: 'location', label: 'Location' },
    { name: 'reason', label: 'Reason' },
    { name: 'user', label: 'User' },
  ],
  transfers: [
    { name: 'date', label: 'Date' },
    { name: 'reference', label: 'Reference' },
    { name: 'direction', label: 'Direction' },
    { name: 'source', label: 'Source' },
    { name: 'destination', label: 'Destination' },
    { name: 'qty', label: 'Qty', align: 'right', format: num },
    { name: 'user', label: 'User' },
  ],
  price: [
    { name: 'date', label: 'Date' },
    { name: 'old_price', label: 'OldPrice', align: 'right', format: (v) => v === null ? '—' : money(v) },
    { name: 'new_price', label: 'NewPrice', align: 'right', format: money },
    { name: 'user', label: 'ChangedBy' },
  ],
}

const hist = reactive(Object.fromEntries(
  HISTORY_TABS.map(t => [t, { rows: [], loading: false, page: 1, lastPage: 1, total: 0, loaded: false }])
))

async function loadHistory (type, page = 1) {
  const slot = hist[type]
  slot.loading = true
  try {
    const { data } = await api.get(`/products/${route.params.id}/history`, { params: { type, page, per_page: 25 } })
    slot.rows = data.data || []
    slot.page = data.current_page || 1
    slot.lastPage = data.last_page || 1
    slot.total = data.total || 0
    slot.loaded = true
  } catch (_) {
    slot.rows = []; slot.total = 0; slot.lastPage = 1
  } finally { slot.loading = false }
}

// Each ledger is fetched the first time its tab is opened, not on page load.
watch(tab, (t) => { if (HISTORY_TABS.includes(t) && !hist[t].loaded) loadHistory(t) })
// Switching product invalidates everything already fetched.
watch(() => route.params.id, () => {
  for (const t of HISTORY_TABS) Object.assign(hist[t], { rows: [], page: 1, lastPage: 1, total: 0, loaded: false })
  if (HISTORY_TABS.includes(tab.value)) loadHistory(tab.value)
})
</script>

<style scoped>
.pd-page { background: #F0F4F8; }
.pd-head { display: flex; align-items: center; gap: 12px; background: #fff; border: 1px solid #E7ECF3; border-radius: 16px; padding: 14px 16px; }
.pd-head__img { width: 64px; height: 64px; border-radius: 14px; overflow: hidden; background: #F1F5F9; border: 1.5px solid #E7ECF3; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pd-head__img img { width: 100%; height: 100%; object-fit: cover; }
.pd-head__name { font-size: 19px; font-weight: 800; color: #0F172A; display: flex; align-items: center; flex-wrap: wrap; }
.pd-head__meta { font-size: 12.5px; color: #64748B; }
.pd-card { border-radius: 16px; padding-bottom: 4px; }
.pd-card__head { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; padding: 12px 14px 6px; }
.pd-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13.5px; color: #334155; }
.pd-empty { color: #94A3B8; text-align: center; padding: 30px; font-size: 13px; }
.pd-bars { width: 100%; height: 140px; padding: 0 10px; }
.pd-bar { fill: #175A8C; }
.pd-bar:hover { fill: #C8862D; }
.pd-axis { display: flex; justify-content: space-between; padding: 4px 14px 8px; font-size: 10.5px; color: #94A3B8; }
.pd-hist { display: flex; align-items: center; gap: 8px; padding: 8px 14px; border-bottom: 1px dashed #EEF2F6; }

/* ── tabs ── */
.pd-tabs {
  margin-top: 10px;
  background: #fff; border: 1px solid #E7ECF3; border-radius: 13px;
  color: #64748B;
}
.pd-tabs :deep(.q-tab) { min-height: 40px; font-size: 12.5px; font-weight: 700; }
.pd-panels { background: transparent; }
.pd-panels :deep(.q-tab-panel) { padding: 0; }
</style>