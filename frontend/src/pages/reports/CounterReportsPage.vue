<template>
  <q-page class="cr-page q-pa-md">

    <!-- ── VIP hero — Counter Command Center ────────────────────── -->
    <div class="cr-hero q-mb-md">
      <div class="cr-hero__left">
        <div class="cr-hero__eyebrow"><q-icon name="point_of_sale" size="15px" /> {{ $t('CounterCommandCenter') }}</div>
        <div class="cr-hero__title">{{ $t('CounterReports') }}</div>
        <div class="cr-hero__sub">{{ $t('CounterReportsHint') }}</div>
        <div class="cr-datenav q-mt-sm">
          <q-btn dense flat round icon="chevron_left" color="white" @click="shiftDay(-1)" />
          <q-input dense standout dark v-model="date" type="date" class="cr-date" @update:model-value="load" />
          <q-btn dense flat round icon="chevron_right" color="white" @click="shiftDay(1)" />
          <q-chip clickable dense square class="cr-today" :class="{ 'cr-today--on': isToday }" @click="goToday">{{ $t('Today') }}</q-chip>
        </div>
      </div>
      <div class="cr-hero__stats">
        <div class="cr-hstat cr-hstat--gold">
          <div class="cr-hstat__val">{{ fmt(totals.net) }} <small>AFN</small></div>
          <div class="cr-hstat__lbl">{{ $t('NetSales') }}</div>
        </div>
        <div class="cr-hstat">
          <div class="cr-hstat__val">{{ totals.orders ?? 0 }}</div>
          <div class="cr-hstat__lbl">{{ $t('Orders') }}</div>
        </div>
        <div class="cr-hstat">
          <div class="cr-hstat__val">{{ fmt(totals.avg_ticket) }}</div>
          <div class="cr-hstat__lbl">{{ $t('AvgTicket') }}</div>
        </div>
        <div class="cr-hstat">
          <div class="cr-hstat__val cr-hstat__val--sm">
            <span class="cr-tdot" style="background:#22C55E"></span>{{ fmt(totals.cash) }}
            <span class="cr-tdot" style="background:#3B82F6"></span>{{ fmt(totals.card) }}
            <span class="cr-tdot" style="background:#F59E0B"></span>{{ fmt(totals.mobile) }}
          </div>
          <div class="cr-hstat__lbl">{{ $t('CashCardMobile') }}</div>
        </div>
      </div>
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="40px" /></div>

    <template v-else>
      <!-- ── Counter cards ──────────────────────────────────────── -->
      <div class="row q-col-gutter-md q-mb-md">
        <div v-for="(c, idx) in counters" :key="c.user_id" class="col-12 col-md-6 col-lg-4">
          <div class="cr-card" :class="{ 'cr-card--best': c.user_id === best && c.net > 0 }">
            <div v-if="c.user_id === best && c.net > 0" class="cr-card__crown"><q-icon name="emoji_events" size="14px" /> {{ $t('BestCounter') }}</div>

            <div class="cr-card__head">
              <div class="cr-avatar">{{ counterNo(c, idx) }}</div>
              <div class="min-w-0">
                <div class="cr-card__name">{{ c.name }}</div>
                <div class="cr-card__role">{{ c.is_counter ? $t('CounterSeat') : $t('OtherSeller') }}</div>
              </div>
              <q-space />
              <q-chip dense square :color="c.shift_open ? 'green-6' : 'blue-grey-5'" text-color="white" class="q-ma-none" :icon="c.shift_open ? 'radio_button_checked' : 'lock'">
                {{ c.shift_open ? $t('ShiftOpen') : $t('Closed') }}
              </q-chip>
            </div>

            <div class="cr-card__net">{{ fmt(c.net) }} <small>AFN</small></div>

            <div class="cr-card__row3">
              <div><b>{{ c.orders }}</b><span>{{ $t('Orders') }}</span></div>
              <div><b>{{ Number(c.items) }}</b><span>{{ $t('Items') }}</span></div>
              <div><b>{{ fmt(c.avg_ticket) }}</b><span>{{ $t('AvgTicket') }}</span></div>
            </div>

            <!-- Tender split -->
            <div class="cr-tenders">
              <div class="cr-tenders__bar">
                <div v-for="t in tenderSegs(c)" :key="t.key" :style="`width:${t.pct}%;background:${t.color}`"></div>
              </div>
              <div class="cr-tenders__legend">
                <span v-for="t in tenderSegs(c)" :key="t.key"><i :style="`background:${t.color}`"></i>{{ $t(t.label) }} {{ fmt(t.val) }}</span>
                <span v-if="!tenderSegs(c).length" class="text-grey-5">{{ $t('NoRecordFound') }}</span>
              </div>
            </div>

            <!-- Hourly curve -->
            <div class="cr-hours">
              <div v-for="(v, h) in c.hourly" :key="h" class="cr-hours__bar"
                :style="`height:${hourPct(c, v)}%`" :class="{ 'cr-hours__bar--on': v > 0 }">
                <q-tooltip v-if="v > 0">{{ String(h).padStart(2, '0') }}:00 — {{ fmt(v) }} AFN</q-tooltip>
              </div>
            </div>
            <div class="cr-hours__axis"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>

            <!-- Drawer sessions -->
            <div v-if="c.shifts.length" class="cr-drawer">
              <div v-for="s in c.shifts" :key="s.id" class="cr-drawer__row">
                <q-icon name="savings" size="14px" color="amber-8" />
                <span class="cr-drawer__time">{{ s.opened_at }} → {{ s.closed_at || '…' }}</span>
                <span class="cr-drawer__float">{{ $t('Float') }} {{ fmt(s.opening_float) }}</span>
                <q-space />
                <template v-if="s.variance !== null">
                  <q-badge :color="s.variance === 0 ? 'green-6' : (s.variance > 0 ? 'teal-6' : 'red-6')">
                    {{ s.variance === 0 ? $t('Balanced') : (s.variance > 0 ? '+' : '') + fmt(s.variance) }}
                  </q-badge>
                </template>
                <q-badge v-else color="blue-grey-4">{{ $t('Open') }}</q-badge>
              </div>
            </div>
            <div v-else class="cr-drawer cr-drawer--none">{{ $t('NoShiftToday') }}</div>

            <!-- Share of day -->
            <div class="cr-share">
              <div class="cr-share__track"><div class="cr-share__bar" :style="`width:${c.share_pct}%`"></div></div>
              <span class="cr-share__pct">{{ c.share_pct }}% {{ $t('OfDay') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Leaderboard ────────────────────────────────────────── -->
      <q-card flat class="cr-tablecard">
        <div class="cr-sec-title q-pa-md q-pb-none"><q-icon name="leaderboard" size="16px" /> {{ $t('DailyLeaderboard') }} — {{ date }}</div>
        <q-markup-table flat dense class="cr-table q-ma-md q-mt-sm">
          <thead>
            <tr>
              <th class="text-left">#</th>
              <th class="text-left">{{ $t('Counter') }}</th>
              <th class="text-right">{{ $t('Orders') }}</th>
              <th class="text-right">{{ $t('Items') }}</th>
              <th class="text-right">{{ $t('Gross') }}</th>
              <th class="text-right">{{ $t('Discounts') }}</th>
              <th class="text-right">{{ $t('Refunds') }}</th>
              <th class="text-right">{{ $t('NetSales') }}</th>
              <th class="text-right">{{ $t('AvgTicket') }}</th>
              <th class="text-right">{{ $t('Share') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(c, i) in counters" :key="c.user_id">
              <td><span class="cr-rank" :class="'cr-rank--' + (i + 1)">{{ i + 1 }}</span></td>
              <td class="text-weight-bold">{{ c.name }}</td>
              <td class="text-right">{{ c.orders }}</td>
              <td class="text-right">{{ Number(c.items) }}</td>
              <td class="text-right">{{ fmt(c.gross) }}</td>
              <td class="text-right">{{ fmt(c.discounts) }}</td>
              <td class="text-right" :class="c.refunds > 0 ? 'text-red-6' : ''">{{ fmt(c.refunds) }}</td>
              <td class="text-right text-weight-bold text-amber-9">{{ fmt(c.net) }}</td>
              <td class="text-right">{{ fmt(c.avg_ticket) }}</td>
              <td class="text-right">{{ c.share_pct }}%</td>
            </tr>
            <tr v-if="!counters.length"><td colspan="10" class="text-center text-grey-5">{{ $t('NoRecordFound') }}</td></tr>
          </tbody>
        </q-markup-table>
      </q-card>
    </template>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/boot/axios'

const date = ref(new Date().toISOString().slice(0, 10))
const totals = ref({})
const counters = ref([])
const best = ref(null)
const loading = ref(false)

const isToday = computed(() => date.value === new Date().toISOString().slice(0, 10))

function fmt (v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 1 }) }
function shiftDay (n) {
  const d = new Date(date.value + 'T00:00:00')
  d.setDate(d.getDate() + n)
  date.value = d.toISOString().slice(0, 10)
  load()
}
function goToday () { date.value = new Date().toISOString().slice(0, 10); load() }

// "Counter 2" -> 2; otherwise initials for non-counter sellers.
function counterNo (c, idx) {
  const m = /(\d+)\s*$/.exec(c.name || '')
  if (m) return m[1]
  return (c.name || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
}

const TENDER_META = [
  { key: 'cash', label: 'Cash', color: '#22C55E' },
  { key: 'card', label: 'Card', color: '#3B82F6' },
  { key: 'mobile', label: 'MobileMoney', color: '#F59E0B' },
  { key: 'credit', label: 'CreditTender', color: '#A855F7' },
]
function tenderSegs (c) {
  const sum = TENDER_META.reduce((s, t) => s + Number(c.tenders?.[t.key] || 0), 0)
  if (sum <= 0) return []
  return TENDER_META
    .map(t => ({ ...t, val: Number(c.tenders[t.key] || 0) }))
    .filter(t => t.val > 0)
    .map(t => ({ ...t, pct: Math.max(3, Math.round(t.val / sum * 100)) }))
}
function hourPct (c, v) {
  const max = Math.max(...c.hourly, 1)
  return v > 0 ? Math.max(12, Math.round(v / max * 100)) : 4
}

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/reports/counters', { params: { date: date.value } })
    totals.value = data.totals
    counters.value = data.counters
    best.value = data.best
  } finally { loading.value = false }
}

onMounted(load)
</script>

<style scoped>
.cr-page { background: #F0F4F8; }

/* Hero */
.cr-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.cr-hero__left { flex: 1 1 300px; }
.cr-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.cr-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.cr-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 4px; max-width: 480px; }
.cr-datenav { display: flex; align-items: center; gap: 6px; }
.cr-date { max-width: 170px; }
.cr-today {
  background: rgba(255, 255, 255, 0.12); color: #fff; font-weight: 700; font-size: 11px;
}
.cr-today--on { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; }
.cr-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; }
.cr-hstat {
  min-width: 120px; padding: 10px 14px; border-radius: 14px;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.cr-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.cr-hstat__val { font-size: 17px; font-weight: 900; }
.cr-hstat__val--sm { font-size: 12px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.cr-hstat--gold .cr-hstat__val { color: #F3D48B; }
.cr-hstat__val small { font-size: 11px; font-weight: 700; color: #9FC1E0; }
.cr-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }
.cr-tdot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-inline-start: 4px; }

/* Counter cards */
.cr-card {
  position: relative; background: #fff; border: 1.5px solid #E7ECF3; border-radius: 18px;
  padding: 16px 18px; height: 100%;
  transition: all 0.2s ease;
}
.cr-card:hover { transform: translateY(-3px); box-shadow: 0 16px 30px -20px rgba(14, 42, 71, 0.6); }
.cr-card--best { border-color: #F3D48B; box-shadow: 0 14px 30px -18px rgba(200, 134, 45, 0.55); }
.cr-card__crown {
  position: absolute; top: -11px; inset-inline-end: 14px;
  display: inline-flex; align-items: center; gap: 4px;
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
  font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.6px;
  padding: 3px 9px; border-radius: 8px;
}
.cr-card__head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.cr-avatar {
  width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  font-size: 17px; font-weight: 900; border: 1.5px solid #F3D48B;
}
.cr-card__name { font-size: 14.5px; font-weight: 800; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cr-card__role { font-size: 10.5px; color: #94A3B8; }
.cr-card__net { font-size: 26px; font-weight: 900; color: #0E2A47; letter-spacing: -0.5px; }
.cr-card__net small { font-size: 13px; color: #94A3B8; font-weight: 700; }
.cr-card__row3 { display: flex; gap: 18px; margin: 8px 0 10px; }
.cr-card__row3 b { display: block; font-size: 14px; color: #0F172A; }
.cr-card__row3 span { font-size: 10.5px; color: #94A3B8; }

.cr-tenders__bar { display: flex; height: 9px; border-radius: 6px; overflow: hidden; background: #F1F5F9; }
.cr-tenders__legend { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 5px; font-size: 10.5px; color: #64748B; }
.cr-tenders__legend i { width: 8px; height: 8px; border-radius: 3px; display: inline-block; margin-inline-end: 4px; }

.cr-hours { display: flex; align-items: flex-end; gap: 2px; height: 44px; margin-top: 12px; }
.cr-hours__bar { flex: 1; background: #EEF2F7; border-radius: 3px 3px 0 0; min-height: 2px; }
.cr-hours__bar--on { background: linear-gradient(180deg, #F3D48B, #C8862D); }
.cr-hours__axis { display: flex; justify-content: space-between; font-size: 9.5px; color: #CBD5E1; margin-top: 2px; }

.cr-drawer { margin-top: 10px; border-top: 1px dashed #EEF2F7; padding-top: 8px; }
.cr-drawer--none { font-size: 11px; color: #CBD5E1; }
.cr-drawer__row { display: flex; align-items: center; gap: 7px; font-size: 11px; color: #64748B; padding: 2px 0; }
.cr-drawer__time { font-weight: 700; color: #334155; }
.cr-drawer__float { color: #94A3B8; }

.cr-share { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.cr-share__track { flex: 1; height: 7px; border-radius: 6px; background: #F1F5F9; overflow: hidden; }
.cr-share__bar { height: 100%; border-radius: 6px; background: linear-gradient(90deg, #17517F, #C8862D); transition: width 0.6s ease; }
.cr-share__pct { font-size: 10.5px; font-weight: 800; color: #64748B; white-space: nowrap; }

/* Leaderboard */
.cr-tablecard { border-radius: 18px; border: 1.5px solid #E7ECF3; }
.cr-sec-title { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 800; color: #0E2A47; }
.cr-table { border: 1.5px solid #EEF2F7; border-radius: 12px; }
.cr-table thead tr { background: #0E2A47; }
.cr-table th { font-size: 11px; font-weight: 800; color: #F3D48B; text-transform: uppercase; letter-spacing: 0.4px; }
.cr-table td { font-size: 12.5px; }
.cr-rank {
  width: 24px; height: 24px; border-radius: 8px; font-size: 11.5px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center;
  background: #F1F5F9; color: #64748B;
}
.cr-rank--1 { background: #FEF3C7; color: #B45309; }
.cr-rank--2 { background: #E2E8F0; color: #475569; }
.cr-rank--3 { background: #FFEDD5; color: #C2410C; }
.min-w-0 { min-width: 0; }
</style>
