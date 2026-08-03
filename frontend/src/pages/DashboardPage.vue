<template>
  <q-page class="db-page q-pa-md">

    <!-- ══ COMMAND HERO — the whole day on one band ══ -->
    <div class="db-hero q-mb-md">
      <div class="db-hero__left">
        <div class="db-hero__eyebrow"><span class="db-live"></span>{{ today }}</div>
        <div class="db-hero__company">{{ companyName }}</div>
        <div class="db-hero__chips">
          <div v-if="$can('report-list')" class="db-chip db-chip--gold">
            <q-icon name="trending_up" size="14px" />
            <span>{{ $t('NetProfitToday') }}</span><b>{{ fmt(anim.profit) }} AFN</b>
          </div>
          <div v-if="$can('product-list')" class="db-chip" :class="{ 'db-chip--warn': (stats.stock_alerts ?? 0) > 0 }">
            <q-icon name="warning_amber" size="14px" />
            <span>{{ $t('LowStock') }}</span><b>{{ stats.stock_alerts ?? 0 }}</b>
          </div>
          <div v-if="$can('expense-list')" class="db-chip">
            <q-icon name="payments" size="14px" />
            <span>{{ $t('ThisMonth') }} · {{ $t('Expenses') }}</span><b>{{ fmt(stats.expenses_month) }}</b>
          </div>
        </div>
      </div>

      <div class="db-hero__center">
        <svg v-if="trend.length && $can('sale-list')" :viewBox="`0 0 ${TW} ${TH}`" class="db-hero__spark" preserveAspectRatio="none">
          <defs>
            <linearGradient id="dbherofill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#F3D48B" stop-opacity="0.35" />
              <stop offset="100%" stop-color="#F3D48B" stop-opacity="0" />
            </linearGradient>
          </defs>
          <polygon :points="trendArea" fill="url(#dbherofill)" />
          <polyline :points="trendLine" fill="none" stroke="#F3D48B" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
          <circle v-if="trendPoints.length" :cx="trendPoints[trendPoints.length - 1].x" :cy="trendPoints[trendPoints.length - 1].y" r="5" fill="#F3D48B" stroke="#0E2A47" stroke-width="2" />
        </svg>
        <div class="db-hero__salewrap">
          <div class="db-hero__salelbl">{{ $t('SalesToday') }}</div>
          <div class="db-hero__saleval">{{ fmt(anim.sales) }}<small> AFN</small></div>
          <div v-if="$can('sale-list')" class="db-hero__salefoot">{{ $t('SalesLast7Days') }} · {{ fmt(trendTotal) }} AFN</div>
        </div>
      </div>

      <div class="db-hero__actions">
        <q-btn unelevated no-caps icon="tune" :label="$t('CustomizeDashboard')" class="db-hero__btn" @click="customizeDlg = true" />
        <q-btn v-if="$can('report-list')" unelevated no-caps icon="assessment" :label="$t('Reports')" to="/reports" class="db-hero__btn db-hero__btn--gold" />
      </div>
    </div>

    <!-- COUNTER / CASHIER HERO — the register seat's personal cockpit -->
    <div v-if="show('hero') && $can('pos-sell')" class="db-pos-hero q-mb-md">
      <div class="db-pos-hero__main">
        <div class="db-pos-hero__eyebrow"><q-icon name="point_of_sale" size="15px" /> {{ $t('MyRegister') }}</div>
        <div class="db-pos-hero__nums">
          <div class="db-pos-hero__stat">
            <div class="db-pos-hero__val">{{ fmt(anim.mySales) }} <small>AFN</small></div>
            <div class="db-pos-hero__lbl">{{ $t('MySalesToday') }}</div>
          </div>
          <div class="db-pos-hero__sep"></div>
          <div class="db-pos-hero__stat">
            <div class="db-pos-hero__val">{{ Math.round(anim.myOrders) }}</div>
            <div class="db-pos-hero__lbl">{{ $t('MyOrdersToday') }}</div>
          </div>
          <div class="db-pos-hero__sep"></div>
          <div class="db-pos-hero__stat">
            <q-chip dense square :color="stats.my_open_shift ? 'green-6' : 'blue-grey-6'" text-color="white" icon="schedule" class="q-ma-none">
              {{ stats.my_open_shift ? $t('ShiftOpen') + ' · ' + shiftSince : $t('NoOpenShift') }}
            </q-chip>
            <div class="db-pos-hero__lbl q-mt-xs">{{ $t('CashDrawer') }}</div>
          </div>
        </div>
      </div>
      <div class="db-pos-hero__cta">
        <q-btn unelevated no-caps size="lg" class="db-pos-hero__btn" icon="storefront" :label="$t('OpenRegister')" to="/pos" />
        <q-btn v-if="$can('shift-list')" outline no-caps color="white" icon="pending_actions" :label="$t('Shifts')" to="/shifts" class="q-mt-sm full-width" />
      </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div v-if="show('quickActions')" class="db-qa-row">
      <router-link v-for="(a, i) in visibleQuickActions" :key="a.to" :to="a.to"
        class="db-qa" :style="`--qa:${a.color};--qa-tint:${a.tint};animation-delay:${i * 70}ms`">
        <span class="db-qa__icon"><q-icon :name="a.icon" size="22px" /></span>
        <span class="db-qa__txt">
          <b>{{ $t(a.label) }}</b>
          <small>{{ $t(a.sub) }}</small>
        </span>
        <q-icon name="arrow_forward" size="15px" class="db-qa__go" />
      </router-link>
    </div>

    <!-- KPIs — full-size cards with count-up -->
    <div v-if="show('kpis')" class="row q-col-gutter-md q-mb-md db-kpis">
      <div class="col-6 col-md-2"><stat-card icon="point_of_sale" :label="$t('SalesToday')" :value="fmt(anim.sales)" suffix="AFN" color="#16A34A" tint="#DCFCE7" /></div>
      <div class="col-6 col-md-2" v-if="$can('report-list')"><stat-card icon="trending_up" :label="$t('NetProfitToday')" :value="fmt(anim.profit)" suffix="AFN" color="#B45309" tint="#FEF3C7" /></div>
      <div class="col-6 col-md-2"><stat-card icon="inventory_2" :label="$t('Products')" :value="Math.round(anim.products)" color="#175A8C" tint="#E0EDF7" /></div>
      <div class="col-6 col-md-2" v-if="$can('product-list')"><stat-card icon="warning" :label="$t('LowStock')" :value="stats.stock_alerts ?? 0" color="#EA580C" tint="#FFEDD5" /></div>
      <div class="col-6 col-md-2" v-if="$can('user-list')"><stat-card icon="groups" :label="$t('Users')" :value="Math.round(anim.users)" color="#7C3AED" tint="#EDE9FE" /></div>
      <div class="col-6 col-md-2" v-if="$can('branch-list')"><stat-card icon="store" :label="$t('Branches')" :value="Math.round(anim.branches)" color="#D97706" tint="#FEF3C7" /></div>
    </div>

    <div class="row q-col-gutter-md items-start">
      <!-- SALES OVER TIME — the big chart owns the row -->
      <div v-if="show('salesOverTime') && $can('sale-list')" class="col-12 col-lg-8">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="bar_chart" size="20px" class="q-mr-xs" />{{ $t('SalesOverTime') }}</div>
            <q-space />
            <q-btn-toggle v-model="range" dense unelevated no-caps size="sm"
              toggle-color="primary" color="grey-3" text-color="grey-8"
              :options="RANGES.map(r => ({ label: $t(r.label), value: r.value }))"
              @update:model-value="loadRange" />
          </q-card-section>
          <q-card-section class="q-pt-none">
            <div v-if="rangeLoading" class="db-sot__state"><q-spinner size="30px" color="primary" /></div>
            <div v-else-if="!sotMax" class="db-sot__state">{{ $t('NoDataInRange') }}</div>
            <template v-else>
              <div class="db-sot">
                <div v-for="b in sot" :key="b.key" class="db-sot__col">
                  <span class="db-sot__amt">{{ shortN(b.total) }}</span>
                  <div class="db-sot__bar" :style="`height:${sotPct(b)}%`">
                    <q-tooltip>{{ b.label }} · {{ fmt(b.total) }} AFN · {{ b.orders }} {{ $t('Orders') }}</q-tooltip>
                  </div>
                  <span class="db-sot__lbl">{{ b.label }}</span>
                </div>
              </div>
              <div class="db-sot__foot">
                <span>{{ $t('Total') }}: <b>{{ fmt(sotTotal) }} AFN</b></span>
                <span>{{ $t('peak') }}: <b>{{ fmt(sotMax) }}</b></span>
              </div>
            </template>
          </q-card-section>
        </q-card>
      </div>

      <!-- RECENT SALES -->
      <div v-if="show('recentSales') && $can('sale-list')" class="col-12 col-lg-4">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="receipt_long" size="18px" class="q-mr-xs" />{{ $t('RecentSales') }}</div>
            <q-space />
            <q-btn dense flat no-caps size="sm" color="primary" :label="$t('Sales')" to="/sales" />
          </q-card-section>
          <q-card-section class="q-pt-none db-feed">
            <div v-if="!(stats.recent_sales || []).length" class="text-caption text-grey-5 q-py-md text-center">{{ $t('NoRecordFound') }}</div>
            <div v-for="s in stats.recent_sales" :key="s.id" class="db-sale">
              <span class="db-sale__ico"><q-icon name="sell" size="15px" /></span>
              <div class="min-w-0">
                <div class="db-sale__inv">{{ s.invoice_no }}</div>
                <div class="db-sale__meta">{{ s.sold_at }} · {{ s.items }} {{ $t('Items') }}</div>
              </div>
              <div class="db-sale__amt">{{ fmt(s.total) }}</div>
            </div>
          </q-card-section>
        </q-card>
      </div>

      <!-- TOP SELLING PRODUCTS — with revenue share bars -->
      <div v-if="show('topProducts') && $can('sale-list')" class="col-12 col-lg-4">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="emoji_events" size="18px" class="q-mr-xs" color="amber-8" />{{ $t('TopSellingProducts') }}</div>
            <q-space />
            <div class="text-caption text-grey-6">{{ $t('Last30Days') }}</div>
          </q-card-section>
          <q-card-section class="q-pt-none db-feed">
            <div v-if="!(stats.top_products || []).length" class="text-caption text-grey-5 q-py-md text-center">{{ $t('NoRecordFound') }}</div>
            <div v-for="(t, i) in stats.top_products" :key="t.name" class="db-top">
              <span class="db-top__rank" :class="'db-top__rank--' + (i + 1)">{{ i + 1 }}</span>
              <div class="db-top__body min-w-0">
                <div class="db-top__line">
                  <span class="db-top__name">{{ t.name }}</span>
                  <span class="db-top__rev">{{ fmt(t.revenue) }}</span>
                </div>
                <div class="db-top__track">
                  <div class="db-top__bar" :style="`width:${topPct(t)}%`"></div>
                </div>
                <div class="db-top__meta">{{ Number(t.qty) }} {{ $t('Sold') }}</div>
              </div>
            </div>
          </q-card-section>
        </q-card>
      </div>

      <!-- LOW STOCK ALERTS -->
      <div v-if="show('lowStock') && $can('product-list')" class="col-12 col-lg-4">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="warning_amber" size="18px" class="q-mr-xs" color="deep-orange" />{{ $t('LowStockAlerts') }}</div>
            <q-space />
            <q-btn dense flat no-caps size="sm" color="primary" :label="$t('Products')" to="/products" />
          </q-card-section>
          <q-card-section class="q-pt-none db-feed">
            <div v-if="!(stats.low_stock_list || []).length" class="text-caption text-grey-5 q-py-md text-center">{{ $t('NoRecordFound') }}</div>
            <div v-for="p in stats.low_stock_list" :key="p.id" class="db-low">
              <div class="db-low__name prod-link" @click="$router.push('/products/' + p.id)">{{ p.name }}</div>
              <q-badge :color="Number(p.stock_qty) <= 0 ? 'red' : 'orange-8'">{{ Number(p.stock_qty) }} {{ $t('Left') }}</q-badge>
              <span class="db-low__min">{{ $t('MinStock') }}: {{ Number(p.min_stock) }}</span>
            </div>
          </q-card-section>
        </q-card>
      </div>

      <!-- CATEGORIES — donut share of the store, click to drill in -->
      <div v-if="show('categories') && $can('product-list')" class="col-12 col-lg-4">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="category" size="18px" class="q-mr-xs" />{{ $t('Categories') }}</div>
            <q-space />
            <q-btn-toggle v-model="catMetric" dense unelevated no-caps size="sm"
              toggle-color="primary" color="grey-3" text-color="grey-8"
              :options="CAT_METRICS.map(m => ({ label: $t(m.label), value: m.value }))" />
          </q-card-section>
          <q-card-section class="q-pt-none">
            <div v-if="!(stats.category_breakdown || []).length" class="text-caption text-grey-5 q-py-md text-center">{{ $t('NoRecordFound') }}</div>
            <template v-else>
              <div class="db-donut-wrap">
                <div class="db-donut" :style="`background:${donutGradient}`">
                  <div class="db-donut__hole">
                    <b>{{ stats.products_total ?? 0 }}</b>
                    <small>{{ $t('Products') }}</small>
                  </div>
                </div>
                <div class="db-donut-legend">
                  <div class="db-cat__hint q-mb-xs">{{ $t('CategoryShareHint') }}</div>
                  <div v-for="(c, i) in stats.category_breakdown" :key="c.name" class="db-cat"
                    role="button" tabindex="0"
                    @click="openCategory(c)" @keyup.enter="openCategory(c)">
                    <span class="db-cat__swatch" :style="`background:${catColors[i % catColors.length]}`"></span>
                    <span class="db-cat__name">{{ c.name }}<q-icon name="chevron_right" size="14px" class="db-cat__go" /></span>
                    <span class="db-cat__meta">{{ catMeta(c) }}</span>
                    <b class="db-cat__n">{{ catShare(c) }}%</b>
                  </div>
                </div>
              </div>
            </template>
          </q-card-section>
        </q-card>
      </div>

      <!-- RECENT EXPENSES -->
      <div v-if="show('expenses') && $can('expense-list')" class="col-12 col-lg-6">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="payments" size="18px" class="q-mr-xs" color="deep-orange" />{{ $t('RecentExpenses') }}</div>
            <q-space />
            <span class="db-exp__month">{{ $t('ThisMonth') }}: <b>{{ fmt(stats.expenses_month) }}</b></span>
            <q-btn dense flat no-caps size="sm" color="primary" :label="$t('Expenses')" to="/finance/expenses" />
          </q-card-section>
          <q-card-section class="q-pt-none db-feed">
            <div v-if="!(stats.recent_expenses || []).length" class="text-caption text-grey-5 q-py-md text-center">{{ $t('NoRecordFound') }}</div>
            <div v-for="e in stats.recent_expenses" :key="e.id" class="db-exp">
              <span class="db-exp__ico"><q-icon :name="EXPENSE_ICONS[e.category] || 'receipt'" size="15px" /></span>
              <div class="min-w-0">
                <div class="db-exp__cat">{{ $t(catKey(e.category)) }}</div>
                <div class="db-exp__meta">{{ e.payee || '—' }} · {{ $fmtDate(e.date) }}</div>
              </div>
              <div class="db-exp__amt">− {{ fmt(e.amount) }}</div>
            </div>
          </q-card-section>
        </q-card>
      </div>

      <!-- LIVE ACTIVITY -->
      <div v-if="show('activity') && $can('log-list')" class="col-12 col-lg-6">
        <q-card flat bordered class="db-card">
          <q-card-section class="q-pb-xs row items-center">
            <div class="db-card__title"><q-icon name="bolt" size="18px" class="q-mr-xs" />{{ $t('LiveActivity') }}</div>
            <q-space /><span class="db-live"></span>
          </q-card-section>
          <q-card-section class="q-pt-none db-feed">
            <div v-if="(stats.recent_activity || []).length === 0" class="text-caption text-grey-5 q-py-md text-center">{{ $t('NoRecordFound') }}</div>
            <div v-for="a in stats.recent_activity" :key="a.id" class="db-feed__item">
              <span class="db-feed__dot" :class="'db-feed__dot--' + a.action"></span>
              <div>
                <div class="db-feed__txt">{{ a.description }}</div>
                <div class="db-feed__meta">{{ a.user?.name || '—' }} · {{ a.created_at_human }}</div>
              </div>
            </div>
          </q-card-section>
        </q-card>
      </div>
    </div>

    <!-- ── Customize dashboard: pick the cards you need ── -->
    <q-dialog v-model="customizeDlg">
      <q-card class="db-custom">
        <q-card-section class="row items-center q-pb-sm">
          <q-avatar icon="tune" color="blue-1" text-color="primary" size="38px" class="q-mr-sm" />
          <div>
            <div class="text-weight-bold">{{ $t('CustomizeDashboard') }}</div>
            <div class="text-caption text-grey-7">{{ $t('CustomizeDashboardHint') }}</div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-section class="q-pt-sm">
          <q-list dense>
            <q-item v-for="c in availableCards" :key="c.key" tag="label" class="db-custom__row">
              <q-item-section avatar style="min-width:34px"><q-icon :name="c.icon" size="18px" color="primary" /></q-item-section>
              <q-item-section>{{ $t(c.label) }}</q-item-section>
              <q-item-section side><q-toggle v-model="prefs[c.key]" color="primary" @update:model-value="savePrefs" /></q-item-section>
            </q-item>
          </q-list>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right">
          <q-btn flat no-caps color="grey-7" :label="$t('ShowAll')" @click="resetPrefs" />
          <q-btn unelevated no-caps color="primary" :label="$t('Done')" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

const { proxy } = getCurrentInstance()
const router = useRouter()
const auth = useAuthStore()
const can = (p) => (proxy?.$can ? proxy.$can(p) : true)

const stats = ref({})
const companyName = ref('Afghan China Shopping Center')

const today = new Date().toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })

// ── Card catalog + per-user visibility preferences (localStorage) ──
const cardDefs = [
  { key: 'hero', label: 'MyRegister', icon: 'point_of_sale', perm: 'pos-sell' },
  { key: 'quickActions', label: 'QuickActions', icon: 'flash_on', perm: null },
  { key: 'kpis', label: 'KeyNumbers', icon: 'insights', perm: null },
  { key: 'salesOverTime', label: 'SalesOverTime', icon: 'bar_chart', perm: 'sale-list' },
  { key: 'recentSales', label: 'RecentSales', icon: 'receipt_long', perm: 'sale-list' },
  { key: 'lowStock', label: 'LowStockAlerts', icon: 'warning_amber', perm: 'product-list' },
  { key: 'topProducts', label: 'TopSellingProducts', icon: 'emoji_events', perm: 'sale-list' },
  { key: 'expenses', label: 'RecentExpenses', icon: 'payments', perm: 'expense-list' },
  { key: 'categories', label: 'Categories', icon: 'category', perm: 'product-list' },
  { key: 'activity', label: 'LiveActivity', icon: 'bolt', perm: 'log-list' },
]
const prefsKey = computed(() => 'acsc-dash-cards:' + (auth.user?.id || 'anon'))
const prefs = reactive(Object.fromEntries(cardDefs.map(c => [c.key, true])))
const customizeDlg = ref(false)

const availableCards = computed(() => cardDefs.filter(c => !c.perm || can(c.perm)))
function show (key) { return prefs[key] !== false }
function savePrefs () { try { localStorage.setItem(prefsKey.value, JSON.stringify(prefs)) } catch (_) {} }
function loadPrefs () {
  try {
    const raw = localStorage.getItem(prefsKey.value)
    if (raw) Object.assign(prefs, JSON.parse(raw))
  } catch (_) {}
}
function resetPrefs () { for (const c of cardDefs) prefs[c.key] = true; savePrefs() }

// Each quick action carries the permission that reveals it.
const quickActions = [
  { to: '/users/create', icon: 'person_add', label: 'User', sub: 'Administration', color: '#175A8C', tint: '#E0EDF7', perm: 'user-create' },
  { to: '/roles', icon: 'rule', label: 'UserRole', sub: 'Administration', color: '#0D9488', tint: '#CCFBF1', perm: 'role-list' },
  { to: '/branches', icon: 'store', label: 'Branch', sub: 'Administration', color: '#16A34A', tint: '#DCFCE7', perm: 'branch-list' },
  { to: '/finance/exchange-rates', icon: 'currency_exchange', label: 'ExchangeRates', sub: 'FinanceAndAccounting', color: '#D97706', tint: '#FEF3C7', perm: 'exchange-rate-list' },
  { to: '/log', icon: 'visibility', label: 'Log', sub: 'System', color: '#7C3AED', tint: '#EDE9FE', perm: 'log-list' },
  { to: '/theme', icon: 'palette', label: 'ThemeAppearance', sub: 'System', color: '#DC2626', tint: '#FEE2E2', perm: 'theme-list' },
]
const visibleQuickActions = computed(() => quickActions.filter(a => can(a.perm)))

function fmt (v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) }
// 12,400 → 12.4K — keeps the value labels above the bars readable.
function shortN (v) {
  const n = Number(v || 0)
  if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M'
  if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K'
  return String(Math.round(n))
}

const shiftSince = computed(() => {
  const t = stats.value.my_open_shift?.opened_at
  if (!t) return ''
  const d = new Date(String(t).replace(' ', 'T'))
  return isNaN(d) ? '' : d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
})

// ── Sales over time: the owner picks the grain ──────────
const RANGES = [
  { label: 'Daily', value: 'day' },
  { label: 'Weekly', value: 'week' },
  { label: 'Monthly', value: 'month' },
  { label: 'Yearly', value: 'year' },
]
const range = ref('day')
const rangeLoading = ref(false)
const sot = ref([])

async function loadRange () {
  rangeLoading.value = true
  try {
    const { data } = await api.get('/dashboard_data', { params: { range: range.value } })
    sot.value = data.sales_over_time || []
  } catch (_) { sot.value = [] } finally { rangeLoading.value = false }
}
const sotMax = computed(() => Math.max(0, ...sot.value.map(b => Number(b.total))))
const sotTotal = computed(() => sot.value.reduce((a, b) => a + Number(b.total), 0))
// A bucket that sold something always shows at least a sliver, so "a little"
// never looks the same as "nothing".
const sotPct = (b) => sotMax.value ? Math.max(Number(b.total) > 0 ? 3 : 0, (Number(b.total) / sotMax.value) * 100) : 0

// ── Categories: which share, the donut, and the drill-through ─────
const CAT_METRICS = [
  { label: 'ByCount', value: 'count' },
  { label: 'ByValue', value: 'value' },
  { label: 'BySold', value: 'sold' },
]
const catMetric = ref('count')
const catShare = (c) => catMetric.value === 'value' ? c.share_value
  : catMetric.value === 'sold' ? c.share_sold : c.share_count
const catMeta = (c) => catMetric.value === 'value' ? fmt(c.stock_value) + ' AFN'
  : catMetric.value === 'sold' ? fmt(c.revenue_30d) + ' AFN'
  : c.count + ' ' + proxy.$t('Products')

const catColors = ['#175A8C', '#0D9488', '#D97706', '#7C3AED', '#DC2626', '#16A34A', '#C8862D', '#64748B']

// The donut is one conic-gradient built from the shares — no chart library,
// matching the app's dependency-free chart idiom.
const donutGradient = computed(() => {
  const cats = stats.value.category_breakdown || []
  if (!cats.length) return '#F1F5F9'
  let acc = 0
  const stops = []
  cats.forEach((c, i) => {
    const share = Math.max(0, Number(catShare(c)) || 0)
    const from = acc; acc += share
    stops.push(`${catColors[i % catColors.length]} ${from}% ${Math.min(acc, 100)}%`)
  })
  if (acc < 100) stops.push(`#F1F5F9 ${acc}% 100%`)
  return `conic-gradient(${stops.join(', ')})`
})

/** Open the catalog filtered to this category. */
function openCategory (c) {
  router.push(c.id ? { path: '/products', query: { category_id: c.id } } : '/products')
}

// ── Top products: revenue share of the leader ───────────
const topMax = computed(() => Math.max(1, ...(stats.value.top_products || []).map(t => Number(t.revenue))))
const topPct = (t) => Math.max(4, Math.round(Number(t.revenue) / topMax.value * 100))

// ── Expenses ───────────────────────────────────────────
const EXPENSE_ICONS = {
  rent: 'home_work', electricity: 'bolt', water: 'water_drop', internet: 'wifi',
  transport: 'local_shipping', wages: 'groups', repairs: 'build',
  cleaning: 'cleaning_services', marketing: 'campaign', government: 'account_balance',
  other: 'receipt',
}
// 'rent' -> 'ExpRent', so the categories can be translated.
const catKey = (c) => 'Exp' + String(c || 'other').charAt(0).toUpperCase() + String(c || 'other').slice(1)

// ── 7-day trend geometry (hero sparkline) ──
const TW = 700
const TH = 150
const trend = computed(() => stats.value.sales_trend || [])
const trendTotal = computed(() => trend.value.reduce((s, p) => s + Number(p.total || 0), 0))
const trendPoints = computed(() => {
  const pts = trend.value
  if (!pts.length) return []
  const max = Math.max(...pts.map(p => Number(p.total)), 1)
  const stepX = pts.length > 1 ? (TW - 30) / (pts.length - 1) : 0
  return pts.map((p, i) => ({
    x: 15 + i * stepX,
    y: TH - 16 - (Number(p.total) / max) * (TH - 36),
  }))
})
const trendLine = computed(() => trendPoints.value.map(p => `${p.x},${p.y}`).join(' '))
const trendArea = computed(() => {
  const pts = trendPoints.value
  if (!pts.length) return ''
  return `${pts[0].x},${TH - 10} ` + pts.map(p => `${p.x},${p.y}`).join(' ') + ` ${pts[pts.length - 1].x},${TH - 10}`
})

// Count-up animation (game feel): numbers roll up to the live values.
const anim = reactive({ sales: 0, products: 0, users: 0, branches: 0, mySales: 0, myOrders: 0, profit: 0 })
let raf = null
function countUp (targets, ms = 1200) {
  const start = performance.now()
  const from = { ...anim }
  const step = (now) => {
    const t = Math.min(1, (now - start) / ms)
    const e = 1 - Math.pow(1 - t, 3)
    for (const k of Object.keys(targets)) anim[k] = from[k] + (targets[k] - from[k]) * e
    if (t < 1) raf = requestAnimationFrame(step)
  }
  raf = requestAnimationFrame(step)
}

async function load () {
  try {
    const { data } = await api.get('/dashboard_data')
    stats.value = data
    countUp({
      sales: Number(data.sales_today || 0),
      products: Number(data.products_total || 0),
      users: Number(data.total_users || 0),
      branches: Number(data.total_branches || 0),
      mySales: Number(data.my_sales_today || 0),
      myOrders: Number(data.my_orders_today || 0),
      profit: Number(data.net_profit_today || 0),
    })
  } catch (_) {}
  try { const { data } = await api.get('/user'); companyName.value = data?.company?.name_en || companyName.value } catch (_) {}
}

onMounted(() => { loadPrefs(); load(); loadRange() })
onUnmounted(() => { if (raf) cancelAnimationFrame(raf) })
</script>

<style scoped>
.db-page { background: #F0F4F8; }
.db-live { width: 8px; height: 8px; border-radius: 50%; background: #22C55E; animation: dblive 2s infinite; display: inline-block; }
@keyframes dblive { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); } 70% { box-shadow: 0 0 0 7px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }

/* ══ Command hero — one band that carries the whole day ══ */
.db-hero {
  position: relative; display: flex; align-items: stretch; gap: 24px; flex-wrap: wrap;
  background: linear-gradient(118deg, #0E2A47 0%, #123A66 52%, #17517F 100%);
  border-radius: 20px; padding: 22px 26px; color: #fff; overflow: hidden;
  box-shadow: 0 24px 44px -26px rgba(14, 42, 71, 0.9);
  animation: qain 0.5s both;
}
.db-hero::before {
  content: ''; position: absolute; width: 380px; height: 380px; border-radius: 50%;
  background: radial-gradient(circle, rgba(243, 212, 139, 0.14), transparent 65%);
  top: -160px; inset-inline-end: -80px; pointer-events: none;
}
.db-hero__left { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.db-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 12px; font-weight: 600; color: #9FC1E0; margin-bottom: 4px;
}
.db-hero__company { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; line-height: 1.15; }
.db-hero__chips { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
.db-chip {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 10px; padding: 5px 10px; font-size: 11.5px; color: #C7DCEF;
  backdrop-filter: blur(4px);
}
.db-chip b { color: #fff; font-variant-numeric: tabular-nums; }
.db-chip--gold { border-color: rgba(243, 212, 139, 0.4); color: #F3D48B; }
.db-chip--gold b { color: #F3D48B; }
.db-chip--warn { border-color: rgba(251, 146, 60, 0.55); color: #FDBA74; }
.db-chip--warn b { color: #FDBA74; }

.db-hero__center {
  position: relative; flex: 1.4 1 340px; min-width: 260px; min-height: 150px;
  display: flex; align-items: center; justify-content: center;
}
.db-hero__spark { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0.9; }
.db-hero__salewrap { position: relative; text-align: center; }
.db-hero__salelbl {
  font-size: 11px; font-weight: 800; letter-spacing: 2.4px; text-transform: uppercase;
  color: #9FC1E0; text-shadow: 0 2px 10px rgba(14, 42, 71, 0.9);
}
.db-hero__saleval {
  font-size: 42px; font-weight: 900; letter-spacing: -1.2px; line-height: 1.05;
  font-variant-numeric: tabular-nums; text-shadow: 0 4px 18px rgba(14, 42, 71, 0.95);
}
.db-hero__saleval small { font-size: 16px; font-weight: 700; color: #9FC1E0; }
.db-hero__salefoot { font-size: 11px; color: #C7DCEF; margin-top: 3px; text-shadow: 0 2px 10px rgba(14, 42, 71, 0.9); }

.db-hero__actions { display: flex; flex-direction: column; gap: 10px; justify-content: center; min-width: 190px; }
.db-hero__btn {
  background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 12px; font-weight: 700;
}
.db-hero__btn--gold { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; border: none; }

/* Counter hero — deep navy register cockpit with a gold CTA */
.db-pos-hero {
  display: flex; align-items: stretch; gap: 18px; flex-wrap: wrap;
  background: linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 14px; padding: 14px 18px; color: #fff;
  box-shadow: 0 18px 34px -22px rgba(14, 42, 71, 0.85);
  animation: qain 0.5s both;
}
.db-pos-hero__main { flex: 1 1 320px; min-width: 0; }
.db-pos-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase;
  color: #F3D48B; margin-bottom: 10px;
}
.db-pos-hero__nums { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
.db-pos-hero__val { font-size: 22px; font-weight: 900; letter-spacing: -0.4px; line-height: 1.1; }
.db-pos-hero__val small { font-size: 12px; font-weight: 700; color: #9FC1E0; }
.db-pos-hero__lbl { font-size: 10px; color: #9FC1E0; margin-top: 2px; }
.db-pos-hero__sep { width: 1px; align-self: stretch; background: rgba(255, 255, 255, 0.14); }
.db-pos-hero__cta { display: flex; flex-direction: column; justify-content: center; min-width: 190px; }
.db-pos-hero__btn {
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
  font-weight: 900; border-radius: 12px;
}

/* Quick actions — staggered entrance, shine sweep on hover */
.db-qa-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
.db-qa {
  position: relative; display: flex; align-items: center; gap: 10px;
  background: #fff; border: 1.5px solid #E7ECF3; border-radius: 14px;
  padding: 10px 12px; text-decoration: none; overflow: hidden;
  animation: qain 0.5s both; transition: all 0.22s ease;
}
@keyframes qain { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
.db-qa::after {
  content: ''; position: absolute; top: 0; bottom: 0; width: 40px;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.85), transparent);
  inset-inline-start: -60px; transform: skewX(-20deg);
}
.db-qa:hover { transform: translateY(-3px); border-color: var(--qa); box-shadow: 0 14px 26px -18px var(--qa); }
.db-qa:hover::after { animation: qashine 0.7s ease; }
@keyframes qashine { to { inset-inline-start: 120%; } }
.db-qa__icon {
  width: 34px; height: 34px; border-radius: 11px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: var(--qa-tint); color: var(--qa); transition: transform 0.2s ease;
}
.db-qa:hover .db-qa__icon { transform: scale(1.12) rotate(-6deg); }
.db-qa__txt b { display: block; font-size: 12.5px; color: #0F172A; }
.db-qa__txt small { font-size: 10px; color: #94A3B8; }
.db-qa__go { margin-inline-start: auto; color: var(--qa); opacity: 0; transition: all 0.2s ease; }
.db-qa:hover .db-qa__go { opacity: 1; transform: translateX(3px); }

/* KPI cards — full size, larger numbers */
.db-kpis :deep(.stat-card) { padding: 13px 15px 12px; border-radius: 14px; }
.db-kpis :deep(.stat-card__value) { font-size: 22px; margin-top: 7px; }
.db-kpis :deep(.stat-card__label) { font-size: 10.5px; }
.db-kpis :deep(.stat-card__icon) { width: 32px; height: 32px; border-radius: 10px; }

/* Cards */
.db-card { border-radius: 16px; }
.db-card__title { font-size: 15px; font-weight: 800; color: #175A8C; display: flex; align-items: center; }

/* Recent sales */
.db-sale { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px dashed #F1F5F9; }
.db-sale__ico {
  width: 32px; height: 32px; border-radius: 10px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  background: #E0EDF7; color: #175A8C;
}
.db-sale__inv { font-size: 13px; font-weight: 700; color: #0F172A; }
.db-sale__meta { font-size: 11px; color: #94A3B8; }
.db-sale__amt { margin-inline-start: auto; font-size: 13.5px; font-weight: 800; color: #16A34A; white-space: nowrap; }

/* Low stock */
.db-low { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px dashed #F1F5F9; }
.db-low__name { font-size: 13.5px; font-weight: 600; flex: 1; min-width: 0; }
.db-low__min { font-size: 12px; color: #94A3B8; white-space: nowrap; }

/* Top products — rank + revenue share bar */
.db-top { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; border-bottom: 1px dashed #F1F5F9; }
.db-top__rank {
  width: 26px; height: 26px; border-radius: 9px; flex-shrink: 0; font-size: 12px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center;
  background: #F1F5F9; color: #64748B; margin-top: 2px;
}
.db-top__rank--1 { background: #FEF3C7; color: #B45309; }
.db-top__rank--2 { background: #E2E8F0; color: #475569; }
.db-top__rank--3 { background: #FFEDD5; color: #C2410C; }
.db-top__body { flex: 1; }
.db-top__line { display: flex; justify-content: space-between; gap: 8px; }
.db-top__name { font-size: 13.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-top__rev { font-size: 13px; font-weight: 800; color: #0F172A; white-space: nowrap; }
.db-top__track { height: 6px; border-radius: 5px; background: #F1F5F9; overflow: hidden; margin-top: 5px; }
.db-top__bar { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #F3D48B, #C8862D); transition: width 0.6s ease; }
.db-top__meta { font-size: 11px; color: #94A3B8; margin-top: 3px; }

/* Categories donut */
.db-donut-wrap { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
.db-donut {
  width: 152px; height: 152px; border-radius: 50%; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.05);
}
.db-donut__hole {
  width: 96px; height: 96px; border-radius: 50%; background: #fff;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  box-shadow: 0 4px 14px -8px rgba(15, 23, 42, 0.35);
}
.db-donut__hole b { font-size: 21px; font-weight: 900; color: #0F172A; line-height: 1; }
.db-donut__hole small { font-size: 9.5px; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.6px; margin-top: 3px; }
.db-donut-legend { flex: 1; min-width: 200px; }
.db-cat {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 6px; border-radius: 9px; cursor: pointer; transition: background .14s ease;
  font-size: 12.5px;
}
.db-cat:hover { background: #F1F6FB; }
.db-cat:hover .db-cat__go { opacity: 1; transform: translateX(2px); }
.db-cat:focus-visible { outline: 2px solid var(--q-primary); outline-offset: 1px; }
.db-cat__swatch { width: 11px; height: 11px; border-radius: 4px; flex-shrink: 0; }
.db-cat__name { display: flex; align-items: center; gap: 2px; font-weight: 600; color: #334155; min-width: 0; }
.db-cat__go { opacity: 0; transition: opacity .14s ease, transform .14s ease; color: var(--q-primary); }
.db-cat__meta { margin-inline-start: auto; margin-inline-end: 8px; font-size: 11px; color: #94A3B8; white-space: nowrap; }
.db-cat__n { color: #0F172A; font-variant-numeric: tabular-nums; }
.db-cat__hint { font-size: 10.5px; color: #94A3B8; }

/* Feed */
.db-feed { max-height: 380px; overflow-y: auto; }
.db-feed__item { display: flex; gap: 8px; padding: 8px 0; border-bottom: 1px dashed #F1F5F9; }
.db-feed__dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; background: #CBD5E1; }
.db-feed__dot--created { background: #22C55E; }
.db-feed__dot--updated { background: #3B82F6; }
.db-feed__dot--deleted { background: #EF4444; }
.db-feed__dot--restored { background: #14B8A6; }
.db-feed__txt { font-size: 12.5px; color: #334155; line-height: 1.35; }
.db-feed__meta { font-size: 11px; color: #94A3B8; }

/* Customize dialog */
.db-custom { min-width: 340px; border-radius: 14px; }
.db-custom__row { border-radius: 10px; }
.prod-link { color: var(--q-primary); cursor: pointer; }
.prod-link:hover { text-decoration: underline; }
.min-w-0 { min-width: 0; }

/* ── Sales over time — the big chart ── */
.db-sot { display: flex; align-items: flex-end; gap: 6px; height: 250px; padding-top: 6px; }
.db-sot__col { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.db-sot__amt { font-size: 10px; font-weight: 700; color: #64748B; margin-bottom: 3px; font-variant-numeric: tabular-nums; }
.db-sot__bar {
  width: 100%; max-width: 42px; min-height: 2px;
  border-radius: 7px 7px 0 0;
  background: linear-gradient(180deg, #2E7CC4, #175A8C);
  transition: height .3s ease, filter .15s ease;
}
.db-sot__bar:hover { filter: brightness(1.15); }
.db-sot__lbl { margin-top: 5px; font-size: 10px; color: #94A3B8; white-space: nowrap; }
.db-sot__foot { display: flex; justify-content: space-between; padding-top: 10px; font-size: 12px; color: #64748B; }
.db-sot__state { height: 250px; display: flex; align-items: center; justify-content: center; color: #94A3B8; font-size: 13px; }

/* ── Expenses ── */
.db-exp { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px dashed #F1F5F9; }
.db-exp__ico {
  width: 32px; height: 32px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: #FFEDD5; color: #EA580C;
}
.db-exp__cat { font-size: 13.5px; font-weight: 700; color: #0F172A; }
.db-exp__meta { font-size: 11px; color: #94A3B8; }
.db-exp__amt { margin-inline-start: auto; font-size: 13.5px; font-weight: 800; color: #DC2626; white-space: nowrap; font-variant-numeric: tabular-nums; }
.db-exp__month { font-size: 11px; color: #64748B; margin-inline-end: 6px; }

@media (max-width: 700px) {
  .db-hero { padding: 18px; gap: 14px; }
  .db-hero__saleval { font-size: 32px; }
  .db-hero__company { font-size: 20px; }
  .db-hero__actions { flex-direction: row; min-width: 0; width: 100%; }
  .db-hero__actions .db-hero__btn { flex: 1; }
}
</style>
