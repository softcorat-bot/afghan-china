<template>
  <div class="ht">
    <div v-if="loading" class="ht__state"><q-spinner size="34px" color="primary" /></div>
    <div v-else-if="!rows.length" class="ht__state">
      <q-icon name="history_toggle_off" size="38px" color="grey-4" />
      <div>{{ $t('NoHistoryYet') }}</div>
    </div>

    <template v-else>
      <div class="ht__scroll">
        <table class="ht__table">
          <thead>
            <tr>
              <th v-for="c in columns" :key="c.name" :class="`ht--${c.align || 'left'}`">{{ $t(c.label) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="r.id ?? i">
              <td v-for="c in columns" :key="c.name" :class="`ht--${c.align || 'left'}`">
                <slot :name="`cell-${c.name}`" :row="r" :value="r[c.name]">{{ format(c, r) }}</slot>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="ht__foot">
        <span class="ht__count">{{ total }} {{ $t('Records') }}</span>
        <q-space />
        <q-pagination v-if="lastPage > 1" :model-value="page" :max="lastPage" :max-pages="6"
          dense flat color="primary" boundary-numbers @update:model-value="$emit('page', $event)" />
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * A read-only, server-paginated history table. The per-product and
 * per-customer dashboards each show several of these, so the shape lives in
 * one place: pass columns, rows and the page state, override any cell with a
 * `cell-<name>` slot.
 */
const props = defineProps({
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  page: { type: Number, default: 1 },
  lastPage: { type: Number, default: 1 },
  total: { type: Number, default: 0 },
})
defineEmits(['page'])

function format (col, row) {
  const raw = row[col.name]
  if (typeof col.format === 'function') return col.format(raw, row)
  return raw === null || raw === undefined || raw === '' ? '—' : raw
}
</script>

<style scoped>
.ht { display: flex; flex-direction: column; }
.ht__state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 8px; padding: 44px 16px; color: #94A3B8; font-size: 13px;
}
/* Wide histories scroll inside the card, never the page. */
.ht__scroll { overflow-x: auto; }
.ht__table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ht__table th {
  position: sticky; top: 0; z-index: 1;
  background: #F8FAFC; color: #64748B;
  font-size: 10.5px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase;
  padding: 8px 12px; white-space: nowrap;
  border-bottom: 1px solid #E7ECF3;
}
.ht__table td {
  padding: 9px 12px; color: #334155;
  border-bottom: 1px dashed #EEF2F6; white-space: nowrap;
}
.ht__table tbody tr:hover td { background: #F8FAFC; }
.ht--right { text-align: end; }
.ht--center { text-align: center; }
.ht__foot { display: flex; align-items: center; padding: 8px 12px; }
.ht__count { font-size: 11px; font-weight: 700; color: #94A3B8; }

@media (prefers-color-scheme: dark) {
  .ht__table th { background: #1E293B; }
  .ht__table td { color: #CBD5E1; }
}
</style>
