<template>
  <q-page class="pr-page q-pa-md">

    <!-- ── VIP hero ─────────────────────────────────────────────── -->
    <div class="pr-hero q-mb-md">
      <div class="pr-hero__left">
        <div class="pr-hero__eyebrow"><q-icon name="account_balance_wallet" size="15px" /> {{ $t('StaffFinance') }}</div>
        <div class="pr-hero__title">{{ $t('Payroll') }}</div>
        <div class="pr-hero__sub">{{ $t('PayrollHint') }}</div>
      </div>
      <div class="pr-hero__stats">
        <div class="pr-hstat">
          <div class="pr-hstat__val">{{ runs.length }}</div>
          <div class="pr-hstat__lbl">{{ $t('PayrollRuns') }}</div>
        </div>
        <div class="pr-hstat pr-hstat--gold">
          <div class="pr-hstat__val">{{ fmt(lastNet) }}</div>
          <div class="pr-hstat__lbl">{{ $t('LastRunNet') }}</div>
        </div>
        <div class="pr-gen">
          <q-input dense standout dark v-model="genPeriod" type="month" class="pr-month" />
          <q-btn unelevated no-caps icon="auto_awesome" :label="$t('GenerateRun')" class="pr-goldbtn" :loading="generating" @click="generate" />
        </div>
      </div>
    </div>

    <q-card flat class="pr-card">
      <q-tabs v-model="tab" dense no-caps class="pr-tabs" active-color="amber-9" indicator-color="amber-8" align="left">
        <q-tab name="runs" icon="receipt_long" :label="$t('PayrollRuns')" />
        <q-tab name="salaries" icon="badge" :label="$t('SalaryBook')" />
      </q-tabs>
      <q-separator />

      <!-- ═══ RUNS ═══ -->
      <div v-show="tab === 'runs'" class="q-pa-md">
        <div v-for="r in runs" :key="r.id" class="pr-run" :class="{ 'pr-run--open': openRun?.run?.id === r.id }">
          <div class="pr-run__row" @click="toggleRun(r)">
            <span class="pr-run__badge" :class="r.status === 'paid' ? 'pr-run__badge--paid' : ''">
              <q-icon :name="r.status === 'paid' ? 'task_alt' : 'edit_note'" size="15px" />
            </span>
            <b class="pr-run__period">{{ r.period }}</b>
            <q-chip dense square size="sm" :color="r.status === 'paid' ? 'green-7' : 'orange-8'" text-color="white" class="q-ma-none">
              {{ $t(r.status === 'paid' ? 'Paid' : 'Draft') }}
            </q-chip>
            <span class="pr-run__meta">{{ r.items_count }} {{ $t('Staff') }}</span>
            <q-space />
            <b class="pr-run__net">{{ fmt(r.net_total) }}</b>
            <q-icon :name="openRun?.run?.id === r.id ? 'expand_less' : 'expand_more'" size="18px" color="blue-grey-5" />
          </div>

          <div v-if="openRun?.run?.id === r.id" class="pr-run__body">
            <div class="pr-scroll">
              <q-markup-table flat dense class="pr-table">
                <thead>
                  <tr>
                    <th class="text-left">{{ $t('Staff') }}</th>
                    <th class="text-center">{{ $t('PresentShort') }}</th>
                    <th class="text-center">{{ $t('AbsentShort') }}</th>
                    <th class="text-right">{{ $t('BasicSalary') }}</th>
                    <th class="text-right">{{ $t('Allowances') }}</th>
                    <th class="text-right">{{ $t('Bonus') }}</th>
                    <th class="text-right">{{ $t('Overtime') }}</th>
                    <th class="text-right">{{ $t('Deductions') }}</th>
                    <th class="text-right">{{ $t('NetPay') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="it in openRun.items" :key="it.id">
                    <td class="text-weight-bold">{{ it.user?.name }}</td>
                    <td class="text-center text-green-8">{{ it.present_days }}</td>
                    <td class="text-center" :class="it.absent_days > 0 ? 'text-red-6 text-weight-bold' : ''">{{ it.absent_days }}</td>
                    <td v-for="f in ['basic', 'allowances', 'bonus', 'overtime', 'deductions']" :key="f" class="text-right">
                      <q-input v-if="openRun.run.status === 'draft'" dense outlined type="number" min="0" step="0.01"
                        :model-value="Number(it[f])" class="pr-cell" @change="v => saveItem(it, f, v)" />
                      <template v-else>{{ fmt(it[f]) }}</template>
                    </td>
                    <td class="text-right text-weight-bold text-amber-9">{{ fmt(it.net) }}</td>
                  </tr>
                  <tr class="pr-totals">
                    <td class="text-weight-bold">{{ $t('Total') }}</td>
                    <td colspan="2"></td>
                    <td class="text-right">{{ fmt(openRun.totals?.basic) }}</td>
                    <td class="text-right">{{ fmt(openRun.totals?.allowances) }}</td>
                    <td class="text-right">{{ fmt(openRun.totals?.bonus) }}</td>
                    <td class="text-right">{{ fmt(openRun.totals?.overtime) }}</td>
                    <td class="text-right text-red-6">{{ fmt(openRun.totals?.deductions) }}</td>
                    <td class="text-right text-weight-bold text-amber-9">{{ fmt(openRun.totals?.net) }}</td>
                  </tr>
                </tbody>
              </q-markup-table>
            </div>
            <div class="row justify-end q-gutter-sm q-mt-sm">
              <q-btn v-if="openRun.run.status === 'draft'" outline no-caps color="red-5" icon="delete" :label="$t('DeleteDraft')" @click="removeRun(r)" />
              <q-btn v-if="openRun.run.status === 'draft'" unelevated no-caps color="green-7" icon="task_alt" :label="$t('MarkAsPaid')" :loading="paying" @click="markPaid(r)" />
            </div>
          </div>
        </div>
        <div v-if="!runs.length" class="q-py-xl text-center text-grey-5">
          <q-icon name="auto_awesome" size="30px" color="amber-8" /><br>{{ $t('GenerateFirstRun') }}
        </div>
      </div>

      <!-- ═══ SALARY BOOK ═══ -->
      <div v-show="tab === 'salaries'" class="q-pa-md">
        <div class="pr-list">
          <div class="pr-srow pr-srow--head">
            <div>{{ $t('Staff') }}</div>
            <div>{{ $t('Role') }}</div>
            <div>{{ $t('BasicSalary') }}</div>
          </div>
          <div v-for="s in salaries" :key="s.id" class="pr-srow" :class="{ 'pr-srow--off': !s.active }">
            <div class="pr-srow__who">
              <div class="pr-avatar">{{ initials(s.name) }}</div>
              <div class="min-w-0">
                <div class="pr-srow__name">{{ s.name }}</div>
                <div class="pr-srow__meta">{{ s.email }}</div>
              </div>
            </div>
            <div class="pr-srow__meta">{{ (s.roles || []).join(', ') || '—' }}</div>
            <div class="pr-srow__sal">
              <q-input dense outlined type="number" min="0" step="0.01" :model-value="s.basic_salary" suffix="AFN"
                class="pr-sal-input" :loading="salBusy === s.id" @change="v => saveSalary(s, v)">
                <template #prepend><q-icon name="payments" size="15px" color="amber-9" /></template>
              </q-input>
              <span class="pr-srow__monthly">≈ {{ fmt(s.basic_salary / 30) }} / {{ $t('Day') }}</span>
            </div>
          </div>
        </div>
      </div>
    </q-card>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const tab = ref('runs')
const runs = ref([])
const openRun = ref(null)
const salaries = ref([])
const generating = ref(false)
const paying = ref(false)
const salBusy = ref(null)
const genPeriod = ref(new Date().toISOString().slice(0, 7))

const lastNet = computed(() => Number(runs.value[0]?.net_total || 0))
function fmt (v) { return (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' AFN' }
function initials (name) { return (name || '?').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() }

async function loadRuns () { try { runs.value = (await api.get('/hr/payroll')).data } catch (_) {} }
async function loadSalaries () { try { salaries.value = (await api.get('/hr/payroll/salaries')).data } catch (_) {} }

async function toggleRun (r) {
  if (openRun.value?.run?.id === r.id) { openRun.value = null; return }
  openRun.value = (await api.get('/hr/payroll/' + r.id)).data
}

async function generate () {
  generating.value = true
  try {
    const { data } = await api.post('/hr/payroll/generate', { period: genPeriod.value })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'auto_awesome', message: `Draft ${data.run.period} generated` })
    await loadRuns()
    openRun.value = data
    tab.value = 'runs'
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  } finally { generating.value = false }
}

async function saveItem (it, field, value) {
  try {
    const { data } = await api.put('/hr/payroll/items/' + it.id, { [field]: Number(value) })
    Object.assign(it, data)
    openRun.value = (await api.get('/hr/payroll/' + openRun.value.run.id)).data
    loadRuns()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  }
}

async function markPaid (r) {
  paying.value = true
  try {
    openRun.value = (await api.post(`/hr/payroll/${r.id}/paid`)).data
    Notify.create({ type: 'positive', position: 'bottom', icon: 'task_alt', message: `${r.period} marked as paid` })
    loadRuns()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  } finally { paying.value = false }
}

async function removeRun (r) {
  try {
    await api.delete('/hr/payroll/' + r.id)
    openRun.value = null
    loadRuns()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  }
}

async function saveSalary (s, value) {
  salBusy.value = s.id
  try {
    const { data } = await api.put('/hr/payroll/salaries/' + s.id, { basic_salary: Number(value) })
    s.basic_salary = data.basic_salary
    Notify.create({ type: 'positive', position: 'bottom', icon: 'payments', message: 'Salary updated' })
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  } finally { salBusy.value = null }
}

onMounted(() => { loadRuns(); loadSalaries() })
</script>

<style scoped>
.pr-page { background: #F0F4F8; }
.pr-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.pr-hero__left { flex: 1 1 280px; }
.pr-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.pr-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.pr-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 4px; max-width: 480px; }
.pr-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.pr-hstat {
  min-width: 110px; padding: 10px 14px; border-radius: 14px;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.pr-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.pr-hstat__val { font-size: 17px; font-weight: 900; }
.pr-hstat--gold .pr-hstat__val { color: #F3D48B; }
.pr-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }
.pr-gen { display: flex; align-items: center; gap: 8px; }
.pr-month { max-width: 160px; }
.pr-goldbtn { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; font-weight: 900; border-radius: 11px; }

.pr-card { border-radius: 18px; border: 1px solid #E9EDF3; overflow: hidden; }
.pr-tabs { background: #fff; }

.pr-run { border: 1.5px solid #EEF2F7; border-radius: 14px; margin-bottom: 10px; overflow: hidden; }
.pr-run--open { border-color: #F3D48B; }
.pr-run__row { display: flex; align-items: center; gap: 10px; padding: 11px 16px; cursor: pointer; flex-wrap: wrap; }
.pr-run__row:hover { background: #FAFBFD; }
.pr-run__badge {
  width: 30px; height: 30px; border-radius: 10px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  background: #FEF3C7; color: #B45309;
}
.pr-run__badge--paid { background: #DCFCE7; color: #15803D; }
.pr-run__period { font-size: 15px; color: #0F172A; }
.pr-run__meta { font-size: 11px; color: #94A3B8; }
.pr-run__net { font-size: 14px; color: #0E2A47; }
.pr-run__body { border-top: 1px dashed #EEF2F7; padding: 12px 16px; background: #FBFDFF; }
.pr-scroll { overflow-x: auto; }
.pr-table { border: 1px solid #EEF2F7; border-radius: 12px; min-width: 900px; }
.pr-table thead tr { background: #0E2A47; }
.pr-table th { font-size: 10.5px; font-weight: 800; color: #F3D48B; text-transform: uppercase; letter-spacing: 0.4px; }
.pr-table td { font-size: 12.5px; }
.pr-cell { width: 110px; margin-inline-start: auto; }
.pr-cell :deep(.q-field__control) { border-radius: 8px; background: #fff; }
.pr-totals td { background: #F8FAFC; border-top: 2px solid #E9EDF3; }

.pr-list { border: 1px solid #EEF2F7; border-radius: 14px; overflow: hidden; }
.pr-srow {
  display: grid; grid-template-columns: minmax(220px, 1.6fr) minmax(140px, 1fr) minmax(240px, 1.2fr);
  gap: 12px; align-items: center; padding: 9px 16px; border-bottom: 1px solid #F1F5F9;
}
.pr-srow--head {
  background: #0E2A47; color: #F3D48B; font-size: 10.5px; font-weight: 800;
  letter-spacing: 0.7px; text-transform: uppercase; padding: 11px 16px;
}
.pr-srow--off { opacity: 0.55; }
.pr-srow__who { display: flex; align-items: center; gap: 10px; min-width: 0; }
.pr-avatar {
  width: 36px; height: 36px; border-radius: 11px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  font-size: 12px; font-weight: 900; border: 1.5px solid #F3D48B;
}
.pr-srow__name { font-size: 13px; font-weight: 700; color: #0F172A; }
.pr-srow__meta { font-size: 11px; color: #94A3B8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pr-srow__sal { display: flex; align-items: center; gap: 10px; }
.pr-sal-input { max-width: 190px; }
.pr-srow__monthly { font-size: 10.5px; color: #94A3B8; white-space: nowrap; }
.min-w-0 { min-width: 0; }
</style>
