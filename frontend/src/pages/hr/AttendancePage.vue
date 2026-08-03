<template>
  <q-page class="at-page q-pa-md">

    <!-- ── Hero: Attendance Management (per the approved design) ── -->
    <div class="at-hero q-mb-md">
      <div class="at-hero__left">
        <div class="at-hero__eyebrow"><q-icon name="fact_check" size="15px" /> {{ $t('AttendanceManagement') }}</div>
        <div class="at-hero__title">{{ $t('TakeAttendance') }}</div>
        <div class="at-datenav q-mt-sm">
          <q-btn dense flat round icon="chevron_left" color="white" @click="shiftDay(-1)" />
          <q-input dense standout dark v-model="date" type="date" class="at-date" @update:model-value="load" />
          <q-btn dense flat round icon="chevron_right" color="white" @click="shiftDay(1)" />
          <q-chip clickable dense square class="at-today" :class="{ 'at-today--on': isToday }" @click="goToday">{{ $t('Today') }}</q-chip>
        </div>
      </div>
      <div class="at-hero__stats">
        <div class="at-hstat at-hstat--green">
          <div class="at-hstat__val">{{ presentCount }}</div>
          <div class="at-hstat__lbl">{{ $t('Present') }}</div>
        </div>
        <div class="at-hstat at-hstat--red">
          <div class="at-hstat__val">{{ absentCount }}</div>
          <div class="at-hstat__lbl">{{ $t('Absent') }}</div>
        </div>
        <div class="at-hstat">
          <div class="at-hstat__val">{{ leaveCount }}</div>
          <div class="at-hstat__lbl">{{ $t('OnLeave') }}</div>
        </div>
        <div class="at-hstat at-hstat--gold">
          <div class="at-hstat__val">{{ pct }}%</div>
          <div class="at-hstat__lbl">{{ $t('PresenceRate') }}</div>
        </div>
      </div>
    </div>

    <!-- ── Staff Attendance Record ─────────────────────────────── -->
    <q-card flat class="at-card">
      <div class="at-card__head">
        <div class="at-card__title"><q-icon name="groups" size="16px" /> {{ $t('StaffAttendanceRecord') }}
          <q-badge color="red-1" text-color="red-8" class="q-ml-sm">{{ rows.length }} {{ $t('Staff') }}</q-badge>
        </div>
        <q-space />
        <q-input outlined dense v-model="search" :label="$t('Search')" clearable style="min-width:200px">
          <template #prepend><q-icon name="search" color="amber-9" /></template>
        </q-input>
      </div>

      <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="36px" /></div>
      <div v-else class="at-list">
        <div class="at-row at-row--head">
          <div>#</div>
          <div>{{ $t('Staff') }}</div>
          <div>{{ $t('Attendance') }}</div>
          <div class="text-right">{{ $t('MonthStatistics') }}</div>
        </div>
        <div v-for="(r, i) in visibleRows" :key="r.user_id" class="at-row">
          <div class="at-row__no">{{ String(i + 1).padStart(2, '0') }}</div>
          <div class="at-row__who">
            <div class="at-avatar">{{ initials(r.name) }}</div>
            <div class="min-w-0">
              <div class="at-row__name">{{ r.name }}</div>
              <div class="at-row__meta">{{ (r.roles || []).join(', ') || r.email }}</div>
            </div>
          </div>
          <div class="at-row__mark">
            <q-toggle :model-value="r.status === 'present'" color="green" size="lg" :disable="busyId === r.user_id"
              @update:model-value="v => mark(r, v ? 'present' : 'absent')" />
            <q-chip dense square size="sm" class="q-ma-none" text-color="white"
              :color="r.status === 'present' ? 'green-7' : (r.status === 'absent' ? 'red-6' : (r.status === 'leave' ? 'orange-8' : 'blue-grey-4'))">
              <q-icon :name="r.status === 'present' ? 'check_circle' : (r.status === 'absent' ? 'cancel' : (r.status === 'leave' ? 'beach_access' : 'help'))" size="13px" class="q-mr-xs" />
              {{ $t(r.status === 'present' ? 'Present' : (r.status === 'absent' ? 'Absent' : (r.status === 'leave' ? 'OnLeave' : 'Unmarked'))) }}
            </q-chip>
            <q-btn dense flat no-caps size="sm" :color="r.status === 'leave' ? 'orange-9' : 'blue-grey-4'" icon="beach_access" :label="$t('Leave')" @click="mark(r, 'leave')" />
          </div>
          <div class="at-row__stats">
            <div class="at-stat"><span>{{ $t('Present') }}</span><b class="text-green-8">{{ r.month_present }}</b></div>
            <div class="at-stat"><span>{{ $t('Absent') }}</span><b class="text-red-6">{{ r.month_absent }}</b></div>
            <div class="at-stat"><span>%</span><b class="at-pct" :class="pctClass(r.month_pct)">{{ r.month_pct ?? '—' }}<template v-if="r.month_pct !== null">%</template></b></div>
          </div>
        </div>
        <div v-if="!visibleRows.length" class="q-py-xl text-center text-grey-5">{{ $t('NoRecordFound') }}</div>
      </div>
    </q-card>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const date = ref(new Date().toISOString().slice(0, 10))
const rows = ref([])
const loading = ref(false)
const busyId = ref(null)
const search = ref('')

const isToday = computed(() => date.value === new Date().toISOString().slice(0, 10))
const presentCount = computed(() => rows.value.filter(r => r.status === 'present').length)
const absentCount = computed(() => rows.value.filter(r => r.status === 'absent').length)
const leaveCount = computed(() => rows.value.filter(r => r.status === 'leave').length)
const pct = computed(() => {
  const marked = presentCount.value + absentCount.value
  return marked > 0 ? Math.round(presentCount.value / marked * 100) : 0
})
const visibleRows = computed(() => {
  const n = (search.value || '').toLowerCase()
  return n ? rows.value.filter(r => (r.name || '').toLowerCase().includes(n)) : rows.value
})

function initials (name) { return (name || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() }
function pctClass (p) { return p === null ? '' : p >= 90 ? 'at-pct--good' : p >= 70 ? 'at-pct--mid' : 'at-pct--bad' }
function shiftDay (n) { const d = new Date(date.value + 'T00:00:00'); d.setDate(d.getDate() + n); date.value = d.toISOString().slice(0, 10); load() }
function goToday () { date.value = new Date().toISOString().slice(0, 10); load() }

async function load () {
  loading.value = true
  try {
    // init=1: everyone unmarked becomes PRESENT by default for this date.
    const { data } = await api.get('/hr/attendance', { params: { date: date.value, init: 1 } })
    rows.value = data.rows
  } finally { loading.value = false }
}

// Instant, in-place save — the row's chip and month stats update locally,
// the sheet never reloads.
async function mark (r, status) {
  if (r.status === status) return
  const prev = r.status
  busyId.value = r.user_id
  r.status = status // optimistic
  try {
    await api.post('/hr/attendance', { user_id: r.user_id, date: date.value, status })
    // shift this person's month counters by the delta
    if (prev === 'present') r.month_present--
    if (prev === 'absent') r.month_absent--
    if (prev === 'leave') r.month_leave--
    if (status === 'present') r.month_present++
    if (status === 'absent') r.month_absent++
    if (status === 'leave') r.month_leave++
    const total = r.month_present + r.month_absent
    r.month_pct = total > 0 ? Math.round(r.month_present / total * 1000) / 10 : null
  } catch (e) {
    r.status = prev // roll back
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  } finally { busyId.value = null }
}

onMounted(load)
</script>

<style scoped>
.at-page { background: #F0F4F8; }
.at-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.at-hero__left { flex: 1 1 300px; }
.at-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.at-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.at-datenav { display: flex; align-items: center; gap: 6px; }
.at-date { max-width: 170px; }
.at-today { background: rgba(255, 255, 255, 0.12); color: #fff; font-weight: 700; font-size: 11px; }
.at-today--on { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; }
.at-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; }
.at-hstat {
  min-width: 96px; padding: 10px 14px; border-radius: 14px; text-align: center;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.at-hstat--green { background: rgba(34, 197, 94, 0.15); border-color: rgba(34, 197, 94, 0.4); }
.at-hstat--red { background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.4); }
.at-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.at-hstat__val { font-size: 19px; font-weight: 900; }
.at-hstat--green .at-hstat__val { color: #86EFAC; }
.at-hstat--red .at-hstat__val { color: #FCA5A5; }
.at-hstat--gold .at-hstat__val { color: #F3D48B; }
.at-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }

.at-card { border-radius: 18px; border: 1px solid #E9EDF3; overflow: hidden; }
.at-card__head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; flex-wrap: wrap; }
.at-card__title { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 800; color: #0F172A; }
.at-list { border-top: 1px solid #EEF2F7; }
.at-row {
  display: grid; grid-template-columns: 44px minmax(200px, 1.6fr) minmax(250px, 1.4fr) minmax(200px, 1fr);
  gap: 12px; align-items: center; padding: 9px 16px; border-bottom: 1px solid #F1F5F9;
}
.at-row--head {
  background: #0E2A47; color: #F3D48B; font-size: 10.5px; font-weight: 800;
  letter-spacing: 0.7px; text-transform: uppercase; padding: 11px 16px;
}
.at-row:hover:not(.at-row--head) { background: #FAFBFD; }
.at-row__no { font-size: 11px; color: #94A3B8; font-weight: 700; }
.at-row__who { display: flex; align-items: center; gap: 10px; min-width: 0; }
.at-avatar {
  width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  font-size: 13px; font-weight: 900; border: 1.5px solid #F3D48B;
}
.at-row__name { font-size: 13px; font-weight: 700; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.at-row__meta { font-size: 10.5px; color: #94A3B8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.at-row__mark { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.at-row__stats { display: flex; gap: 14px; justify-content: flex-end; }
.at-stat { background: #F8FAFC; border: 1px solid #EEF2F7; border-radius: 10px; padding: 5px 10px; text-align: center; min-width: 62px; }
.at-stat span { display: block; font-size: 9.5px; color: #94A3B8; }
.at-stat b { font-size: 13px; }
.at-pct--good { color: #15803D; }
.at-pct--mid { color: #B45309; }
.at-pct--bad { color: #B91C1C; }
.min-w-0 { min-width: 0; }
</style>
