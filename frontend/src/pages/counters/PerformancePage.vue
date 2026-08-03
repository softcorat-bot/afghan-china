<template>
  <q-page class="pf-page q-pa-md">

    <!-- ── Hero: identity + period picker ───────────────────────── -->
    <div class="pf-hero q-mb-md">
      <div class="pf-hero__left">
        <div class="pf-hero__eyebrow">
          <q-icon :name="isCounter ? 'point_of_sale' : 'person'" size="15px" />
          {{ $t(isCounter ? 'CounterPerformance' : 'WorkerPerformance') }}
        </div>
        <div class="pf-hero__title">{{ title }}</div>
        <div v-if="!isCounter && data.user" class="pf-hero__sub">{{ data.user.email }} · {{ (data.user.roles || []).join(', ') }}</div>
        <div class="pf-periods q-mt-sm">
          <q-chip v-for="p in periods" :key="p.key" clickable dense square class="pf-chip" :class="{ 'pf-chip--on': period === p.key }" @click="setPeriod(p.key)">
            {{ $t(p.label) }}
          </q-chip>
          <q-input dense standout dark v-model="customFrom" type="date" class="pf-date" @update:model-value="setCustom" />
          <span class="text-blue-3">→</span>
          <q-input dense standout dark v-model="customTo" type="date" class="pf-date" @update:model-value="setCustom" />
        </div>
      </div>

      <!-- Score dial -->
      <div class="pf-score">
        <svg viewBox="0 0 120 120" class="pf-dial">
          <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="10" />
          <circle cx="60" cy="60" r="52" fill="none" :stroke="gradeColor" stroke-width="10" stroke-linecap="round"
            :stroke-dasharray="`${(stats.score || 0) / 100 * 326.7} 326.7`" transform="rotate(-90 60 60)" />
          <text x="60" y="56" text-anchor="middle" class="pf-dial__num">{{ stats.score ?? 0 }}</text>
          <text x="60" y="76" text-anchor="middle" class="pf-dial__grade">{{ stats.grade || '—' }}</text>
        </svg>
        <div class="pf-score__label" :style="`color:${gradeColor}`">{{ $t(stats.label || 'NoRecordFound') }}</div>
        <div class="pf-score__sub">{{ $t('SpeedScore') }}</div>
      </div>
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="40px" /></div>
    <template v-else>
      <!-- ── KPI tiles ──────────────────────────────────────────── -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-6 col-md-2"><stat-card dense icon="payments" :label="$t('Revenue')" :value="fmt(stats.revenue)" color="#175A8C" tint="#E0EDF7" /></div>
        <div class="col-6 col-md-2"><stat-card dense icon="receipt_long" :label="$t('Orders')" :value="stats.orders ?? 0" color="#0D9488" tint="#CCFBF1" /></div>
        <div class="col-6 col-md-2"><stat-card dense icon="shopping_basket" :label="$t('Items')" :value="Number(stats.items || 0)" color="#7C3AED" tint="#EDE9FE" /></div>
        <div class="col-6 col-md-2"><stat-card dense icon="speed" :label="$t('OrdersPerHour')" :value="stats.orders_per_hour ?? 0" color="#B45309" tint="#FEF3C7" /></div>
        <div class="col-6 col-md-2"><stat-card dense icon="timer" :label="$t('MinutesPerSale')" :value="stats.avg_minutes_per_sale ?? 0" color="#DC2626" tint="#FEE2E2" /></div>
        <div class="col-6 col-md-2"><stat-card dense icon="attach_money" :label="$t('AvgTicket')" :value="fmt(stats.avg_ticket)" color="#16A34A" tint="#DCFCE7" /></div>
      </div>

      <!-- Benchmark strip -->
      <div class="pf-bench q-mb-md">
        <q-icon name="balance" size="15px" color="amber-9" />
        <span>{{ $t('CompanyBenchmark') }}:</span>
        <b>{{ data.benchmark?.orders_per_hour ?? 0 }}</b> {{ $t('OrdersPerHour') }} ·
        <b>{{ fmt(data.benchmark?.revenue_per_hour) }}</b> / {{ $t('Hour') }} ·
        <b>{{ data.benchmark?.items_per_order ?? 0 }}</b> {{ $t('ItemsPerOrder') }}
        <q-space />
        <span class="text-grey-6">{{ data.from }} → {{ data.to }}</span>
      </div>

      <div class="row q-col-gutter-md">
        <!-- Hourly activity -->
        <div class="col-12 col-lg-6">
          <div class="pf-panel">
            <div class="pf-panel__title"><q-icon name="schedule" size="15px" /> {{ $t('HourlyActivity') }}</div>
            <div class="pf-hours">
              <div v-for="(h, i) in data.hourly || []" :key="i" class="pf-hours__bar" :style="`height:${hourPct(h)}%`" :class="{ 'pf-hours__bar--on': h.total > 0 }">
                <q-tooltip v-if="h.total > 0">{{ String(i).padStart(2, '0') }}:00 — {{ fmt(h.total) }} · {{ h.orders }} {{ $t('Orders') }}</q-tooltip>
              </div>
            </div>
            <div class="pf-hours__axis"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
          </div>
        </div>

        <!-- Trend -->
        <div class="col-12 col-lg-6">
          <div class="pf-panel">
            <div class="pf-panel__title"><q-icon name="show_chart" size="15px" /> {{ $t('Trend') }}</div>
            <div class="pf-hours" style="height:110px">
              <div v-for="t in data.trend || []" :key="t.period" class="pf-hours__bar pf-hours__bar--on" :style="`height:${trendPct(t)}%`">
                <q-tooltip>{{ t.period }} — {{ fmt(t.revenue) }} · {{ t.orders }} {{ $t('Orders') }}</q-tooltip>
              </div>
              <div v-if="!(data.trend || []).length" class="pf-empty">{{ $t('NoRecordFound') }}</div>
            </div>
          </div>
        </div>

        <!-- COUNTER MODE: workers on this counter -->
        <div v-if="isCounter" class="col-12">
          <div class="pf-panel">
            <div class="pf-panel__title"><q-icon name="groups" size="15px" /> {{ $t('WorkersOnThisCounter') }}</div>
            <q-markup-table flat dense class="pf-table">
              <thead>
                <tr>
                  <th class="text-left">{{ $t('Worker') }}</th>
                  <th class="text-right">{{ $t('Orders') }}</th>
                  <th class="text-right">{{ $t('Revenue') }}</th>
                  <th class="text-right">{{ $t('OrdersPerHour') }}</th>
                  <th class="text-right">{{ $t('RevenuePerHour') }}</th>
                  <th class="text-left">{{ $t('SpeedScore') }}</th>
                  <th class="text-left">{{ $t('LastSale') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!(data.workers || []).length"><td colspan="8" class="text-center text-grey-5">{{ $t('NoRecordFound') }}</td></tr>
                <tr v-for="w in data.workers" :key="w.user_id">
                  <td class="text-weight-bold">{{ w.name }}</td>
                  <td class="text-right">{{ w.orders }}</td>
                  <td class="text-right">{{ fmt(w.revenue) }}</td>
                  <td class="text-right">{{ w.orders_per_hour }}</td>
                  <td class="text-right">{{ fmt(w.revenue_per_hour) }}</td>
                  <td>
                    <div class="pf-wscore">
                      <div class="pf-wscore__track"><div class="pf-wscore__bar" :style="`width:${w.score}%;background:${gColor(w.grade)}`"></div></div>
                      <b :style="`color:${gColor(w.grade)}`">{{ w.score }} · {{ w.grade }}</b>
                    </div>
                  </td>
                  <td class="text-grey-6" style="font-size:11px">{{ w.last_sale }}</td>
                  <td><q-btn dense flat no-caps size="sm" color="primary" icon="person" :label="$t('Profile')" :to="'/users/' + w.user_id + '/performance'" /></td>
                </tr>
              </tbody>
            </q-markup-table>
          </div>
        </div>

        <!-- USER MODE: counters worked + shifts -->
        <div v-if="!isCounter" class="col-12 col-lg-6">
          <div class="pf-panel">
            <div class="pf-panel__title"><q-icon name="point_of_sale" size="15px" /> {{ $t('CountersWorked') }}</div>
            <div v-for="c in data.counters || []" :key="c.id" class="pf-line">
              <b class="pf-line__name">{{ c.name }}</b>
              <span class="pf-line__sub">{{ c.orders }} {{ $t('Orders') }}</span>
              <b class="pf-line__val">{{ fmt(c.revenue) }}</b>
            </div>
            <div v-if="!(data.counters || []).length" class="pf-empty">{{ $t('NoRecordFound') }}</div>

            <div class="pf-panel__title q-mt-md"><q-icon name="pending_actions" size="15px" /> {{ $t('Shifts') }}</div>
            <div v-for="s in data.shifts || []" :key="s.id" class="pf-line">
              <span class="pf-line__sub">#{{ s.id }} · {{ (s.opened_at || '').slice(0, 16).replace('T', ' ') }}</span>
              <q-chip dense square size="sm" :color="s.status === 'open' ? 'green-6' : 'blue-grey-5'" text-color="white" class="q-ma-none">{{ s.status }}</q-chip>
              <b class="pf-line__val">{{ fmt(s.total_sales) }}</b>
            </div>
            <div v-if="!(data.shifts || []).length" class="pf-empty">{{ $t('NoRecordFound') }}</div>
          </div>
        </div>

        <!-- USER MODE: activity trail -->
        <div v-if="!isCounter" class="col-12 col-lg-6">
          <div class="pf-panel">
            <div class="pf-panel__title"><q-icon name="bolt" size="15px" /> {{ $t('ActivityTrail') }}
              <q-space />
              <q-chip v-for="m in data.by_module || []" :key="m.module" dense square size="sm" color="blue-grey-1" text-color="blue-grey-8" class="q-ma-none q-ml-xs">{{ m.module }} · {{ m.n }}</q-chip>
            </div>
            <div class="pf-feed">
              <div v-for="a in data.activities || []" :key="a.id" class="pf-feed__item">
                <span class="pf-feed__dot" :class="'pf-feed__dot--' + a.action"></span>
                <div class="min-w-0">
                  <div class="pf-feed__txt">{{ a.description }}</div>
                  <div class="pf-feed__meta">{{ a.module }} · {{ (a.created_at || '').replace('T', ' ').slice(0, 16) }}</div>
                </div>
              </div>
              <div v-if="!(data.activities || []).length" class="pf-empty">{{ $t('NoRecordFound') }}</div>
            </div>
          </div>
        </div>

        <!-- COUNTER MODE: recent sales -->
        <div v-if="isCounter" class="col-12">
          <div class="pf-panel">
            <div class="pf-panel__title"><q-icon name="receipt_long" size="15px" /> {{ $t('RecentSales') }}</div>
            <div v-for="r in data.recent || []" :key="r.invoice_no" class="pf-line">
              <b style="flex:0 0 110px">{{ r.invoice_no }}</b>
              <span class="pf-line__name">{{ r.user || '—' }}</span>
              <span class="pf-line__sub">{{ r.time }}</span>
              <b class="pf-line__val">{{ fmt(r.total) }}</b>
            </div>
            <div v-if="!(data.recent || []).length" class="pf-empty">{{ $t('NoRecordFound') }}</div>
          </div>
        </div>
      </div>
    </template>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/boot/axios'

const route = useRoute()
const isCounter = computed(() => route.name === 'counter-detail')

const data = ref({})
const loading = ref(false)
const period = ref('today')
const customFrom = ref('')
const customTo = ref('')

const periods = [
  { key: 'today', label: 'Today' },
  { key: '7d', label: 'Last7Days' },
  { key: 'month', label: 'ThisMonth' },
  { key: 'year', label: 'ThisYear' },
  { key: 'lifetime', label: 'Lifetime' },
]

const title = computed(() => isCounter.value ? (data.value.counter?.name || '…') : (data.value.user?.name || '…'))
const stats = computed(() => data.value.stats || {})
const gradeColor = computed(() => gColor(stats.value.grade))
function gColor (g) { return { A: '#22C55E', B: '#F3D48B', C: '#FB923C', D: '#EF4444' }[g] || '#94A3B8' }

function fmt (v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 1 }) }
const maxHour = computed(() => Math.max(...(data.value.hourly || []).map(h => Number(h.total)), 1))
function hourPct (h) { return h.total > 0 ? Math.max(10, Math.round(h.total / maxHour.value * 100)) : 4 }
const maxTrend = computed(() => Math.max(...(data.value.trend || []).map(t => Number(t.revenue)), 1))
function trendPct (t) { return Math.max(8, Math.round(t.revenue / maxTrend.value * 100)) }

function rangeFor (key) {
  const iso = d => d.toISOString().slice(0, 10)
  const now = new Date()
  if (key === 'today') return [iso(now), iso(now)]
  if (key === '7d') return [iso(new Date(Date.now() - 6 * 864e5)), iso(now)]
  if (key === 'month') return [iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)]
  if (key === 'year') return [`${now.getFullYear()}-01-01`, iso(now)]
  return ['2000-01-01', iso(now)]
}

function setPeriod (key) { period.value = key; customFrom.value = ''; customTo.value = ''; load() }
function setCustom () { if (customFrom.value && customTo.value) { period.value = 'custom'; load() } }

async function load () {
  loading.value = true
  try {
    const [from, to] = period.value === 'custom' ? [customFrom.value, customTo.value] : rangeFor(period.value)
    const granularity = period.value === 'year' || period.value === 'lifetime' ? 'monthly' : 'daily'
    const url = isCounter.value
      ? `/counters/${route.params.id}/performance`
      : `/users/${route.params.id}/performance`
    data.value = (await api.get(url, { params: { from, to, granularity } })).data
  } finally { loading.value = false }
}

watch(() => route.fullPath, load)
onMounted(load)
</script>

<style scoped>
.pf-page { background: #F0F4F8; }
.pf-hero {
  display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.pf-hero__left { flex: 1 1 320px; }
.pf-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.pf-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.pf-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 2px; }
.pf-periods { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pf-chip { background: rgba(255, 255, 255, 0.1); color: #DCEAF7; font-weight: 700; font-size: 11.5px; }
.pf-chip--on { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; }
.pf-date { max-width: 150px; }

.pf-score { text-align: center; }
.pf-dial { width: 128px; height: 128px; }
.pf-dial__num { font-size: 30px; font-weight: 900; fill: #fff; }
.pf-dial__grade { font-size: 15px; font-weight: 800; fill: #F3D48B; }
.pf-score__label { font-size: 13px; font-weight: 900; margin-top: 2px; }
.pf-score__sub { font-size: 10px; color: #9FC1E0; letter-spacing: 1.4px; text-transform: uppercase; }

.pf-bench {
  display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
  background: #fff; border: 1px solid #E9EDF3; border-radius: 12px;
  padding: 9px 14px; font-size: 12px; color: #475569;
}
.pf-panel { background: #fff; border: 1px solid #EEF2F7; border-radius: 14px; padding: 14px 16px; height: 100%; }
.pf-panel__title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; color: #0F172A; margin-bottom: 8px; flex-wrap: wrap; }
.pf-hours { display: flex; align-items: flex-end; gap: 2px; height: 90px; }
.pf-hours__bar { flex: 1; background: #EEF2F7; border-radius: 3px 3px 0 0; min-height: 3px; }
.pf-hours__bar--on { background: linear-gradient(180deg, #F3D48B, #C8862D); }
.pf-hours__axis { display: flex; justify-content: space-between; font-size: 9.5px; color: #CBD5E1; margin-top: 2px; }
.pf-table { border: 1px solid #EEF2F7; border-radius: 12px; }
.pf-table thead tr { background: #0E2A47; }
.pf-table th { font-size: 10.5px; font-weight: 800; color: #F3D48B; text-transform: uppercase; letter-spacing: 0.4px; }
.pf-table td { font-size: 12.5px; }
.pf-wscore { display: flex; align-items: center; gap: 8px; min-width: 150px; }
.pf-wscore__track { flex: 1; height: 7px; border-radius: 6px; background: #F1F5F9; overflow: hidden; }
.pf-wscore__bar { height: 100%; border-radius: 6px; }
.pf-line { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px dashed #F1F5F9; font-size: 12.5px; }
.pf-line__name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: #0F172A; }
.pf-line__sub { font-size: 10.5px; color: #94A3B8; white-space: nowrap; }
.pf-line__val { white-space: nowrap; color: #0E2A47; }
.pf-empty { font-size: 12px; color: #94A3B8; text-align: center; padding: 12px 0; }
.pf-feed { max-height: 320px; overflow-y: auto; }
.pf-feed__item { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px dashed #F1F5F9; }
.pf-feed__dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; background: #CBD5E1; }
.pf-feed__dot--created { background: #22C55E; }
.pf-feed__dot--updated { background: #3B82F6; }
.pf-feed__dot--deleted { background: #EF4444; }
.pf-feed__dot--restored { background: #14B8A6; }
.pf-feed__txt { font-size: 12px; color: #334155; line-height: 1.3; }
.pf-feed__meta { font-size: 10.5px; color: #94A3B8; }
.min-w-0 { min-width: 0; }
</style>
