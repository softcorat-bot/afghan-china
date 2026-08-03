<template>
  <q-page class="cd-page q-pa-md">
    <div v-if="loading" class="text-center q-pa-xl"><q-spinner size="48px" color="primary" /></div>

    <template v-else-if="customer">
      <!-- ── hero: who they are and what they have earned ────────── -->
      <div class="cd-hero" :style="{ '--tier': loyalty.tier_color || '#5A7185' }">
        <q-btn flat round dense icon="arrow_back" color="white" class="cd-hero__back" @click="$router.back()" />

        <div class="cd-hero__who">
          <div class="cd-avatar">{{ initials }}</div>
          <div>
            <div class="cd-hero__name">
              {{ customer.name }}
              <q-chip v-if="customer.type === 'wholesale'" dense size="sm" color="amber-8" text-color="white" class="q-ml-sm">
                {{ $t('WholesaleB2B') }}
              </q-chip>
            </div>
            <div class="cd-hero__meta">
              <span v-if="customer.company_name">{{ customer.company_name }} · </span>
              <span v-if="customer.phone"><q-icon name="phone" size="12px" /> {{ customer.phone }} · </span>
              <span>{{ $t('CustomerSince') }} {{ fmtDate(customer.created_at) }}</span>
            </div>
          </div>
        </div>

        <!-- the badge -->
        <div class="cd-badge" :class="{ 'cd-badge--none': !loyalty.tier }">
          <!-- Progress through the band toward the next badge, drawn as a ring
               around the medal. A conic gradient rather than q-knob, because
               the ring has to sit on navy and take the tier's own colour. -->
          <div class="cd-ring" :style="{ '--pct': (loyalty.progress || 0) + '%' }">
            <div class="cd-ring__face">
              <q-icon :name="loyalty.tier ? 'workspace_premium' : 'lock_outline'" size="30px" />
            </div>
            <span class="cd-ring__pct">{{ loyalty.progress || 0 }}%</span>
          </div>
          <div class="cd-badge__txt">
            <div class="cd-badge__tier">{{ loyalty.tier_label || $t('NoTierYet') }}</div>
            <div class="cd-badge__spend">{{ money(loyalty.spend) }}</div>
            <div v-if="loyalty.gift_eligible" class="cd-badge__gift">
              <q-icon name="redeem" size="13px" /> {{ loyalty.gift }}
            </div>
            <div v-else-if="loyalty.next_tier" class="cd-badge__next">
              {{ money(loyalty.to_next_tier) }} {{ $t('SpendToNextTier') }} ({{ loyalty.next_tier }})
            </div>
          </div>
        </div>
      </div>

      <!-- tier ladder -->
      <div class="cd-ladder">
        <div v-for="t in tiers" :key="t.key" class="cd-step"
          :class="{ 'cd-step--held': loyalty.spend >= t.from, 'cd-step--next': loyalty.next_tier === t.label }"
          :style="{ '--step': t.color }">
          <q-icon :name="loyalty.spend >= t.from ? 'check_circle' : 'radio_button_unchecked'" size="15px" />
          <b>{{ $t(t.label) }}</b>
          <small>{{ money(t.from) }}</small>
        </div>
      </div>

      <!-- KPIs -->
      <div class="row q-col-gutter-md q-mt-sm">
        <div class="col-6 col-md-3"><stat-card dense icon="receipt_long" :label="$t('Orders')" :value="stats.orders" color="#175A8C" tint="#E0EDF7" /></div>
        <div class="col-6 col-md-3"><stat-card dense icon="payments" :label="$t('LifetimeSpend')" :value="money(stats.spend)" color="#16A34A" tint="#DCFCE7" /></div>
        <div class="col-6 col-md-3"><stat-card dense icon="shopping_basket" :label="$t('AvgBasket')" :value="money(stats.avg_basket)" color="#7C3AED" tint="#EDE9FE" /></div>
        <div class="col-6 col-md-3"><stat-card dense icon="stars" :label="$t('LoyaltyPoints')" :value="loyalty.points" color="#C8862D" tint="#FEF3C7" /></div>
      </div>

      <q-tabs v-model="tab" dense no-caps align="left" class="cd-tabs"
        active-color="primary" indicator-color="primary" narrow-indicator>
        <q-tab name="overview" icon="dashboard" :label="$t('Overview')" />
        <q-tab name="sales" icon="receipt_long" :label="$t('SalesHistory')" />
        <q-tab name="items" icon="inventory_2" :label="$t('Items')" />
      </q-tabs>

      <q-tab-panels v-model="tab" animated keep-alive class="cd-panels">
        <!-- ── overview ── -->
        <q-tab-panel name="overview" class="q-pa-none">
          <div class="row q-col-gutter-md q-mt-sm">
            <div class="col-12 col-md-7">
              <q-card flat bordered class="cd-card">
                <div class="cd-card__head"><q-icon name="bar_chart" size="18px" class="q-mr-xs" />{{ $t('MonthlySpend') }}</div>
                <div v-if="monthMax === 0" class="cd-empty">{{ $t('NoHistoryYet') }}</div>
                <template v-else>
                  <svg class="cd-bars" viewBox="0 0 360 140" preserveAspectRatio="none">
                    <rect v-for="(m, i) in months" :key="m.month" class="cd-bar"
                      :x="i * 30 + 5" :y="140 - barH(m.total)" :width="20" :height="barH(m.total)" rx="3">
                      <title>{{ m.month }} · {{ money(m.total) }} · {{ m.orders }} {{ $t('Orders') }}</title>
                    </rect>
                  </svg>
                  <div class="cd-axis">
                    <span>{{ months[0]?.month }}</span>
                    <span>{{ money(monthMax) }} {{ $t('peak') }}</span>
                    <span>{{ months[months.length - 1]?.month }}</span>
                  </div>
                </template>
              </q-card>
            </div>

            <div class="col-12 col-md-5">
              <q-card flat bordered class="cd-card">
                <div class="cd-card__head"><q-icon name="favorite" size="18px" class="q-mr-xs" />{{ $t('FavouriteProducts') }}</div>
                <div v-if="!favourites.length" class="cd-empty">{{ $t('NoHistoryYet') }}</div>
                <div v-for="(f, i) in favourites" :key="f.product_id" class="cd-fav">
                  <span class="cd-fav__n">{{ i + 1 }}</span>
                  <router-link class="cd-fav__name" :to="`/products/${f.product_id}`">{{ f.name }}</router-link>
                  <span class="cd-fav__qty">{{ num(f.qty) }}×</span>
                  <b class="cd-fav__total">{{ money(f.total) }}</b>
                </div>
              </q-card>
            </div>
          </div>
        </q-tab-panel>

        <!-- ── ledgers ── -->
        <q-tab-panel v-for="t in HISTORY_TABS" :key="t" :name="t" class="q-pa-none">
          <q-card flat bordered class="cd-card q-mt-sm">
            <div class="cd-card__head">
              <q-icon :name="t === 'sales' ? 'receipt_long' : 'inventory_2'" size="18px" class="q-mr-xs" />
              {{ $t(t === 'sales' ? 'SalesHistory' : 'Items') }}
            </div>
            <history-table
              :columns="COLUMNS[t]" :rows="hist[t].rows" :loading="hist[t].loading"
              :page="hist[t].page" :last-page="hist[t].lastPage" :total="hist[t].total"
              @page="p => loadHistory(t, p)">
              <template #cell-date="{ value }">{{ fmtDate(value) }}</template>
              <template #cell-channel="{ value }">
                <q-chip dense size="sm" :color="value === 'wholesale' ? 'amber-1' : 'blue-1'"
                  :text-color="value === 'wholesale' ? 'amber-9' : 'blue-9'">
                  {{ $t(value === 'wholesale' ? 'WholesaleB2B' : 'Retail') }}
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
import { api } from '@/boot/axios'
import HistoryTable from '@/components/tables/HistoryTable.vue'

const route = useRoute()
const loading = ref(true)
const customer = ref(null)
const loyalty = ref({})
const tiers = ref([])
const stats = ref({})
const months = ref([])
const favourites = ref([])

const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' AFN'
const num = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })
const fmtDate = (v) => v ? new Date(v).toLocaleDateString() : '—'

const initials = computed(() => (customer.value?.name || '?')
  .split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase())

async function load () {
  loading.value = true
  try {
    const { data } = await api.get(`/customers/${route.params.id}/dashboard`)
    customer.value = data.customer
    loyalty.value = data.loyalty || {}
    tiers.value = data.tiers || []
    stats.value = data.stats || {}
    months.value = data.months || []
    favourites.value = data.favourites || []
  } catch (_) { customer.value = null } finally { loading.value = false }
}
onMounted(load)
watch(() => route.params.id, load)

// monthly bars
const monthMax = computed(() => Math.max(0, ...months.value.map(m => Number(m.total))))
const barH = (v) => monthMax.value ? (Number(v) / monthMax.value) * 128 : 0

// ── ledgers ──
const tab = ref('overview')
const HISTORY_TABS = ['sales', 'items']
const COLUMNS = {
  sales: [
    { name: 'date', label: 'Date' },
    { name: 'reference', label: 'Invoice' },
    { name: 'channel', label: 'Type' },
    { name: 'user', label: 'Cashier' },
    { name: 'items', label: 'Items', align: 'right' },
    { name: 'total', label: 'Total', align: 'right', format: money },
    { name: 'paid', label: 'Paid', align: 'right', format: money },
  ],
  items: [
    { name: 'date', label: 'Date' },
    { name: 'reference', label: 'Invoice' },
    { name: 'name', label: 'Product' },
    { name: 'qty', label: 'Qty', align: 'right', format: num },
    { name: 'unit_price', label: 'UnitPrice', align: 'right', format: money },
    { name: 'total', label: 'Total', align: 'right', format: money },
  ],
}

const hist = reactive(Object.fromEntries(
  HISTORY_TABS.map(t => [t, { rows: [], loading: false, page: 1, lastPage: 1, total: 0, loaded: false }])
))

async function loadHistory (type, page = 1) {
  const slot = hist[type]
  slot.loading = true
  try {
    const { data } = await api.get(`/customers/${route.params.id}/history`, { params: { type, page, per_page: 25 } })
    slot.rows = data.data || []
    slot.page = data.current_page || 1
    slot.lastPage = data.last_page || 1
    slot.total = data.total || 0
    slot.loaded = true
  } catch (_) {
    slot.rows = []; slot.total = 0; slot.lastPage = 1
  } finally { slot.loading = false }
}

watch(tab, (t) => { if (HISTORY_TABS.includes(t) && !hist[t].loaded) loadHistory(t) })
watch(() => route.params.id, () => {
  for (const t of HISTORY_TABS) Object.assign(hist[t], { rows: [], page: 1, lastPage: 1, total: 0, loaded: false })
  if (HISTORY_TABS.includes(tab.value)) loadHistory(tab.value)
})
</script>

<style scoped>
.cd-page { background: #F0F4F8; }

/* ── hero: navy, with the badge's own colour bleeding in ── */
.cd-hero {
  position: relative;
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 16px 20px 16px 8px;
  border-radius: 18px;
  background:
    radial-gradient(120% 160% at 100% 0%, color-mix(in srgb, var(--tier) 55%, transparent), transparent 60%),
    linear-gradient(120deg, #0E2A47, #123A66, #17517F);
  color: #EAF2FA;
  overflow: hidden;
}
.cd-hero__back { flex-shrink: 0; }
.cd-hero__who { display: flex; align-items: center; gap: 13px; flex: 1; min-width: 240px; }
.cd-avatar {
  width: 52px; height: 52px; border-radius: 15px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(255, 255, 255, 0.12);
  border: 1.5px solid rgba(255, 255, 255, 0.22);
  font-size: 18px; font-weight: 900; letter-spacing: 0.5px;
}
.cd-hero__name { font-size: 20px; font-weight: 800; display: flex; align-items: center; flex-wrap: wrap; }
.cd-hero__meta { font-size: 12px; color: #9FC1E0; margin-top: 2px; }

.cd-badge { display: flex; align-items: center; gap: 13px; flex-shrink: 0; }

.cd-ring {
  position: relative;
  width: 82px; height: 82px; flex-shrink: 0;
  border-radius: 50%;
  background: conic-gradient(
    color-mix(in srgb, var(--tier) 60%, #FFF) var(--pct),
    rgba(255, 255, 255, 0.18) var(--pct)
  );
  display: flex; align-items: center; justify-content: center;
}
.cd-ring__face {
  width: 64px; height: 64px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0.06));
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: color-mix(in srgb, var(--tier) 35%, #FFF);
}
.cd-ring__pct {
  position: absolute; bottom: -2px; inset-inline-end: -2px;
  padding: 1px 5px; border-radius: 7px;
  background: #0B2743; border: 1px solid rgba(255, 255, 255, 0.2);
  font-size: 9.5px; font-weight: 800; color: #EAF2FA;
}
.cd-badge--none .cd-ring__face { color: rgba(255, 255, 255, 0.6); }
.cd-badge__tier {
  font-size: 11px; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase;
  color: color-mix(in srgb, var(--tier) 45%, #FFF);
}
.cd-badge__spend { font-size: 22px; font-weight: 900; letter-spacing: -0.5px; font-variant-numeric: tabular-nums; }
.cd-badge__gift {
  margin-top: 3px; font-size: 11.5px; font-weight: 700;
  color: #FFE7A8; display: flex; align-items: center; gap: 4px;
}
.cd-badge__next { margin-top: 3px; font-size: 11px; color: #9FC1E0; }
.cd-badge--none .cd-badge__ring { opacity: 0.65; }

/* ── the ladder of badges ── */
.cd-ladder { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.cd-step {
  display: flex; align-items: center; gap: 6px;
  flex: 1 1 130px;
  padding: 7px 11px; border-radius: 12px;
  background: #fff; border: 1.5px solid #E7ECF3; color: #94A3B8;
  font-size: 11.5px;
}
.cd-step b { color: #64748B; }
.cd-step small { margin-inline-start: auto; font-variant-numeric: tabular-nums; }
.cd-step--held { border-color: var(--step); color: var(--step); background: color-mix(in srgb, var(--step) 7%, #fff); }
.cd-step--held b { color: var(--step); }
.cd-step--next { border-style: dashed; border-color: var(--step); }

.cd-tabs {
  margin-top: 10px;
  background: #fff; border: 1px solid #E7ECF3; border-radius: 13px; color: #64748B;
}
.cd-tabs :deep(.q-tab) { min-height: 40px; font-size: 12.5px; font-weight: 700; }
.cd-panels { background: transparent; }
.cd-panels :deep(.q-tab-panel) { padding: 0; }

.cd-card { border-radius: 16px; padding-bottom: 4px; height: 100%; }
.cd-card__head { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; padding: 12px 14px 6px; }
.cd-empty { color: #94A3B8; text-align: center; padding: 40px; font-size: 13px; }

.cd-bars { width: 100%; height: 150px; padding: 0 8px; }
.cd-bar { fill: #175A8C; }
.cd-bar:hover { fill: #C8862D; }
.cd-axis { display: flex; justify-content: space-between; padding: 4px 14px 8px; font-size: 10.5px; color: #94A3B8; }

.cd-fav { display: flex; align-items: center; gap: 9px; padding: 7px 14px; border-bottom: 1px dashed #EEF2F6; font-size: 13px; }
.cd-fav__n {
  width: 20px; height: 20px; border-radius: 7px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: #E0EDF7; color: #175A8C; font-size: 10.5px; font-weight: 800;
}
.cd-fav__name { flex: 1; min-width: 0; color: #1E293B; text-decoration: none; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cd-fav__name:hover { color: var(--q-primary); text-decoration: underline; }
.cd-fav__qty { color: #94A3B8; font-size: 11.5px; white-space: nowrap; }
.cd-fav__total { color: #0F172A; white-space: nowrap; font-variant-numeric: tabular-nums; }

@media (prefers-color-scheme: dark) {
  .cd-step { background: #1E293B; border-color: #334155; }
  .cd-fav__name { color: #E2E8F0; }
}
</style>
