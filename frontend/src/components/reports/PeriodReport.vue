<template>
  <div class="pr">
    <!-- filters -->
    <div class="row q-col-gutter-sm items-center q-mb-md">
      <div class="col-12 col-md-auto">
        <q-btn-toggle :model-value="f.granularity" no-caps unelevated dense
          toggle-color="primary" color="grey-3" text-color="grey-8"
          :options="GRANULARITIES.map(g => ({ label: $t(g.label), value: g.value }))"
          @update:model-value="v => emitChange({ granularity: v })" />
      </div>
      <div class="col-6 col-md-2">
        <q-input outlined dense :model-value="f.from" type="date" :label="$t('From')"
          @update:model-value="v => emitChange({ from: v })" />
      </div>
      <div class="col-6 col-md-2">
        <q-input outlined dense :model-value="f.to" type="date" :label="$t('To')"
          @update:model-value="v => emitChange({ to: v })" />
      </div>
      <div class="col-6 col-md-2" v-if="people.length">
        <q-select outlined dense :model-value="f.user_id" :options="people" :label="$t('Person')"
          emit-value map-options clearable @update:model-value="v => emitChange({ user_id: v })">
          <template #prepend><q-icon name="person" color="primary" /></template>
        </q-select>
      </div>
      <slot name="filters" />
    </div>

    <!-- summary -->
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-6 col-md-3">
        <stat-card dense icon="payments" :label="$t('Revenue')" :value="fmt(summary.revenue)" color="#175A8C" tint="#E0EDF7" />
      </div>
      <div class="col-6 col-md-3">
        <stat-card dense icon="receipt_long" :label="$t('Orders')" :value="summary.orders ?? 0" color="#7C3AED" tint="#EDE9FE" />
      </div>
      <div class="col-6 col-md-3">
        <stat-card dense icon="trending_up" :label="$t(withMainCost ? 'DeclaredProfit' : 'Profit')"
          :value="fmt(summary.declared_profit)" color="#0D9488" tint="#CCFBF1" />
      </div>
      <!-- Owner-only: the same report, costed at the real buy price. -->
      <div class="col-6 col-md-3" v-if="withMainCost">
        <stat-card dense icon="mdi-diamond-stone" :label="$t('RealProfit')" :value="fmt(summary.real_profit)" color="#B45309" tint="#FEF3C7" />
      </div>
      <div class="col-6 col-md-3" v-else>
        <stat-card dense icon="percent" :label="$t('Margin')" :value="margin + '%'" color="#D97706" tint="#FEF3C7" />
      </div>
      <div class="col-6 col-md-3" v-if="withMainCost">
        <stat-card dense icon="visibility_off" :label="$t('HiddenMargin')" :value="fmt(summary.hidden_margin)" color="#7C3AED" tint="#EDE9FE" />
      </div>
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="primary" size="34px" /></div>
    <template v-else>
      <!-- by period -->
      <div class="pr-title"><q-icon name="event" size="16px" /> {{ $t('ByPeriod') }}</div>
      <div class="pr-scroll q-mb-lg">
        <table class="pr-table">
          <thead>
            <tr>
              <th class="l">{{ $t('Period') }}</th>
              <th class="r">{{ $t('Orders') }}</th>
              <th class="r">{{ $t('Revenue') }}</th>
              <th class="r">{{ $t(withMainCost ? 'DeclaredCost' : 'Cost') }}</th>
              <th class="r">{{ $t(withMainCost ? 'DeclaredProfit' : 'Profit') }}</th>
              <th class="r" v-if="withMainCost">{{ $t('RealCost') }}</th>
              <th class="r" v-if="withMainCost">{{ $t('RealProfit') }}</th>
              <th class="r" v-if="withMainCost">{{ $t('HiddenMargin') }}</th>
              <th class="r" v-if="!withMainCost">{{ $t('Margin') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!periods.length"><td :colspan="withMainCost ? 8 : 6" class="pr-none">{{ $t('NoRecordFound') }}</td></tr>
            <tr v-for="r in periods" :key="r.period">
              <td class="l"><b>{{ r.period }}</b></td>
              <td class="r">{{ r.orders }}</td>
              <td class="r">{{ fmt(r.revenue) }}</td>
              <td class="r">{{ fmt(r.declared_cost) }}</td>
              <td class="r pr-pos">{{ fmt(r.declared_profit) }}</td>
              <td class="r" v-if="withMainCost">{{ fmt(r.real_cost) }}</td>
              <td class="r pr-real" v-if="withMainCost">{{ fmt(r.real_profit) }}</td>
              <td class="r pr-hidden" v-if="withMainCost">{{ fmt(r.hidden_margin) }}</td>
              <td class="r" v-if="!withMainCost">{{ rowMargin(r) }}%</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- by person -->
      <div class="pr-title"><q-icon name="groups" size="16px" /> {{ $t('ByPerson') }}</div>
      <div class="pr-scroll">
        <table class="pr-table">
          <thead>
            <tr>
              <th class="l">{{ $t('Period') }}</th>
              <th class="l">{{ $t('Person') }}</th>
              <th class="r">{{ $t('Orders') }}</th>
              <th class="r">{{ $t('Revenue') }}</th>
              <th class="r">{{ $t(withMainCost ? 'DeclaredProfit' : 'Profit') }}</th>
              <th class="r" v-if="withMainCost">{{ $t('RealProfit') }}</th>
              <th class="r" v-if="withMainCost">{{ $t('HiddenMargin') }}</th>
              <th class="r" v-if="!withMainCost">{{ $t('Margin') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!byUser.length"><td :colspan="withMainCost ? 7 : 6" class="pr-none">{{ $t('NoRecordFound') }}</td></tr>
            <tr v-for="(r, i) in byUser" :key="i">
              <td class="l">{{ r.period }}</td>
              <td class="l"><b>{{ r.user_name }}</b></td>
              <td class="r">{{ r.orders }}</td>
              <td class="r">{{ fmt(r.revenue) }}</td>
              <td class="r pr-pos">{{ fmt(r.declared_profit) }}</td>
              <td class="r pr-real" v-if="withMainCost">{{ fmt(r.real_profit) }}</td>
              <td class="r pr-hidden" v-if="withMainCost">{{ fmt(r.hidden_margin) }}</td>
              <td class="r" v-if="!withMainCost">{{ rowMargin(r) }}%</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * THE period report — one design for both audiences.
 *
 * The server computes both cost bases in a single pass and hands over the
 * real-cost columns only to a Main Cost holder, so the owner's report and the
 * manager's report are literally the same report: same revenue, same orders,
 * priced from the main price for the owner and the normal price for everyone
 * else. This component renders whichever set it was given.
 */
import { computed } from 'vue'

const props = defineProps({
  filters: { type: Object, required: true },
  summary: { type: Object, default: () => ({}) },
  periods: { type: Array, default: () => [] },
  byUser: { type: Array, default: () => [] },
  people: { type: Array, default: () => [] },
  withMainCost: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})
const emit = defineEmits(['change'])

const GRANULARITIES = [
  { label: 'Daily', value: 'daily' },
  { label: 'Weekly', value: 'weekly' },
  { label: 'Monthly', value: 'monthly' },
  { label: 'Yearly', value: 'yearly' },
]

const f = computed(() => props.filters)
const fmt = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })

const margin = computed(() => {
  const rev = Number(props.summary?.revenue || 0)
  return rev > 0 ? Math.round((Number(props.summary?.declared_profit || 0) / rev) * 1000) / 10 : 0
})
const rowMargin = (r) => {
  const rev = Number(r.revenue || 0)
  return rev > 0 ? Math.round((Number(r.declared_profit || 0) / rev) * 1000) / 10 : 0
}

function emitChange (patch) { emit('change', patch) }
</script>

<style scoped>
.pr-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 800; color: #175A8C;
  text-transform: uppercase; letter-spacing: 0.06em;
  margin-bottom: 7px;
}
/* Wide reports scroll inside the card, never the page. */
.pr-scroll { overflow-x: auto; border: 1px solid #E7ECF3; border-radius: 12px; background: #fff; }
.pr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pr-table th {
  background: #F8FAFC; color: #64748B;
  font-size: 10.5px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase;
  padding: 8px 12px; white-space: nowrap; border-bottom: 1px solid #E7ECF3;
}
.pr-table td { padding: 8px 12px; color: #334155; white-space: nowrap; border-bottom: 1px dashed #EEF2F6; font-variant-numeric: tabular-nums; }
.pr-table tbody tr:hover td { background: #F8FAFC; }
.pr-table .l { text-align: start; }
.pr-table .r { text-align: end; }
.pr-none { text-align: center; color: #94A3B8; padding: 26px; }
.pr-pos { color: #0D9488; font-weight: 700; }
.pr-real { color: #B45309; font-weight: 800; }
.pr-hidden { color: #7C3AED; font-weight: 700; }

@media (prefers-color-scheme: dark) {
  .pr-scroll { background: #1E293B; border-color: #334155; }
  .pr-table th { background: #1E293B; }
  .pr-table td { color: #CBD5E1; }
}
</style>
