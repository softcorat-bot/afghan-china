<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="local_shipping" controlRoomButton="false" class="q-mt-xs">
            {{ data.supplier?.name || $t('Supplier') }}
          </m-header>
        </div>

        <!-- The numbers that matter, at the top — same cards as everywhere -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-md">
            <div class="col-6 col-sm-3"><stat-card dense icon="shopping_cart" :label="$t('TotalPurchases')" :value="data.stats?.purchases ?? 0" color="#175A8C" tint="#E0EDF7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="payments" :label="$t('PurchaseValue')" :value="fmt(data.stats?.value)" color="#16A34A" tint="#DCFCE7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="keyboard_return" :label="$t('Returns')" :value="fmt(data.stats?.returned)" color="#EA580C" tint="#FFEDD5" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="account_balance_wallet" :label="$t('TotalPayable')" :value="fmt(data.stats?.outstanding)" color="#7C3AED" tint="#EDE9FE" /></div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="col-12 q-mt-sm">
          <q-tabs v-model="tab" dense no-caps active-color="primary" indicator-color="primary" align="left" class="bg-white my_radio_less sd-tabs">
            <q-tab name="overview" icon="dashboard" :label="$t('Overview')" />
            <q-tab name="purchases" icon="shopping_cart" :label="$t('Purchases')" />
            <q-tab name="returns" icon="keyboard_return" :label="$t('Returns')" />
            <q-tab name="details" icon="badge" :label="$t('Details')" />
          </q-tabs>
        </div>

        <!-- ═══ OVERVIEW — the 12-month curve and what they supply ═══ -->
        <template v-if="tab === 'overview'">
          <div class="col-12 col-lg-7 q-mt-sm">
            <q-card flat bordered class="sd-card">
              <q-card-section class="q-pb-xs row items-center">
                <div class="sd-card__title"><q-icon name="bar_chart" size="17px" class="q-mr-xs" />{{ $t('MonthlySpend') }}</div>
                <q-space />
                <div class="text-caption text-grey-6">{{ fmt(data.stats?.value) }} · {{ data.stats?.received ?? 0 }} {{ $t('Received') }}</div>
              </q-card-section>
              <q-card-section class="q-pt-none">
                <div v-if="!monthsMax" class="sd-state">{{ $t('NoDataInRange') }}</div>
                <div v-else class="sd-bars">
                  <div v-for="m in data.months" :key="m.month" class="sd-bars__col">
                    <div class="sd-bars__bar" :style="`height:${monthsPct(m)}%`">
                      <q-tooltip>{{ m.month }} · {{ fmt(m.total) }} · {{ m.orders }} {{ $t('Orders') }}</q-tooltip>
                    </div>
                    <span class="sd-bars__lbl">{{ m.month.slice(5) }}</span>
                  </div>
                </div>
              </q-card-section>
            </q-card>
          </div>

          <div class="col-12 col-lg-5 q-mt-sm">
            <q-card flat bordered class="sd-card">
              <q-card-section class="q-pb-xs row items-center">
                <div class="sd-card__title"><q-icon name="inventory_2" size="17px" class="q-mr-xs" />{{ $t('TopProducts') }}</div>
              </q-card-section>
              <q-card-section class="q-pt-none">
                <div v-if="!(data.top_products || []).length" class="sd-state">{{ $t('NoRecordFound') }}</div>
                <div v-for="(t, i) in data.top_products" :key="t.product_id ?? i" class="sd-top">
                  <span class="sd-top__rank">{{ i + 1 }}</span>
                  <div class="sd-top__body">
                    <div class="sd-top__line">
                      <span class="sd-top__name prod-link" @click="t.product_id && $router.push('/products/' + t.product_id)">{{ t.name }}</span>
                      <b>{{ fmt(t.total) }}</b>
                    </div>
                    <div class="sd-top__track"><div class="sd-top__bar" :style="`width:${topPct(t)}%`"></div></div>
                    <div class="sd-top__meta">{{ t.qty }} {{ $t('UnitsReceived') }}</div>
                  </div>
                </div>
              </q-card-section>
            </q-card>
          </div>
        </template>

        <!-- ═══ PURCHASES — the full document history ═══ -->
        <div v-if="tab === 'purchases'" class="col-12">
          <n-table :loading="loading" :data="data.purchases || []" :columns="purchaseColumns"
            v-model:filter="pFilter" tableKey="/suppliers/dashboard/purchases"
            :noEdit="true" :noDelete="true" :noInfo="true">
            <template v-slot:body-cell-reference="props">
              <q-td :props="props">
                <div class="text-weight-bold">{{ props.row.reference }}</div>
                <div v-if="props.row.supplier_invoice" class="text-caption text-grey-6">{{ $t('SupplierInvoice') }}: {{ props.row.supplier_invoice }}</div>
              </q-td>
            </template>
            <template v-slot:body-cell-total="props">
              <q-td :props="props" class="text-weight-bold">{{ fmt(props.row.total) }}</q-td>
            </template>
            <template v-slot:body-cell-paid="props">
              <q-td :props="props">{{ fmt(props.row.paid) }}</q-td>
            </template>
            <template v-slot:body-cell-status="props">
              <q-td :props="props">
                <q-chip dense size="sm" :color="props.row.status === 'received' ? 'green-7' : 'grey-6'" text-color="white">
                  {{ $t(props.row.status === 'received' ? 'Received' : 'SaveDraft') }}
                </q-chip>
              </q-td>
            </template>
          </n-table>
        </div>

        <!-- ═══ RETURNS ═══ -->
        <div v-if="tab === 'returns'" class="col-12">
          <n-table :loading="loading" :data="data.returns || []" :columns="returnColumns"
            v-model:filter="rFilter" tableKey="/suppliers/dashboard/returns"
            :noEdit="true" :noDelete="true" :noInfo="true">
            <template v-slot:body-cell-amount="props">
              <q-td :props="props" class="text-deep-orange text-weight-bold">− {{ fmt(props.row.amount) }}</q-td>
            </template>
          </n-table>
        </div>

        <!-- ═══ DETAILS — the record itself ═══ -->
        <div v-if="tab === 'details'" class="col-12 col-md-6 q-mt-sm">
          <q-card flat bordered class="sd-card">
            <q-card-section>
              <div v-for="row in detailRows" :key="row.label" class="sd-detail">
                <span class="sd-detail__lbl"><q-icon :name="row.icon" size="14px" class="q-mr-xs" />{{ $t(row.label) }}</span>
                <span class="sd-detail__val">{{ row.value || '—' }}</span>
              </div>
            </q-card-section>
          </q-card>
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/boot/axios'

const route = useRoute()
const data = ref({})
const loading = ref(false)
const tab = ref('overview')
const pFilter = ref('')
const rFilter = ref('')

const purchaseColumns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'reference', label: 'Reference', field: 'reference', align: 'left', sortable: true },
  { name: 'purchased_at', label: 'Date', field: row => row.purchased_at || row.created_at, align: 'left', sortable: true },
  { name: 'subtotal', label: 'Subtotal', field: 'subtotal', align: 'left', sortable: true },
  { name: 'total', label: 'Total', field: 'total', align: 'left', sortable: true },
  { name: 'paid', label: 'Paid', field: 'paid', align: 'left', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
]

const returnColumns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'reference', label: 'Reference', field: 'reference', align: 'left', sortable: true },
  { name: 'date', label: 'Date', field: 'created_at', align: 'left', sortable: true },
  { name: 'amount', label: 'Amount', field: 'amount', align: 'left', sortable: true },
  { name: 'reason', label: 'Reason', field: 'reason', align: 'left' },
]

const detailRows = computed(() => {
  const s = data.value.supplier || {}
  const st = data.value.stats || {}
  return [
    { icon: 'local_shipping', label: 'Name', value: s.name },
    { icon: 'call', label: 'Phone', value: s.phone },
    { icon: 'mail', label: 'Email', value: s.email },
    { icon: 'place', label: 'Address', value: s.address },
    { icon: 'flag', label: 'Status', value: s.active ? 'Active' : 'Inactive' },
    { icon: 'event', label: 'Created', value: (s.created_at || '').slice(0, 10) },
    { icon: 'shopping_cart', label: 'TotalPurchases', value: String(st.purchases ?? 0) },
    { icon: 'account_balance_wallet', label: 'TotalPayable', value: fmt(st.outstanding) },
  ]
})

function fmt (v) { return (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' AFN' }

const monthsMax = computed(() => Math.max(0, ...(data.value.months || []).map(m => Number(m.total))))
const monthsPct = (m) => monthsMax.value ? Math.max(Number(m.total) > 0 ? 3 : 0, (Number(m.total) / monthsMax.value) * 100) : 0
const topMax = computed(() => Math.max(1, ...(data.value.top_products || []).map(t => Number(t.total))))
const topPct = (t) => Math.max(4, Math.round(Number(t.total) / topMax.value * 100))

async function load () {
  loading.value = true
  try {
    const { data: d } = await api.get(`/suppliers/${route.params.id}/dashboard`)
    data.value = d
  } finally { loading.value = false }
}

onMounted(load)
</script>

<style scoped>
.sd-tabs { border-radius: 12px; }
.sd-card { border-radius: 14px; }
.sd-card__title { font-size: 14px; font-weight: 800; color: #175A8C; display: flex; align-items: center; }
.sd-state { padding: 34px 0; text-align: center; color: #94A3B8; font-size: 12.5px; }

.sd-bars { display: flex; align-items: flex-end; gap: 5px; height: 190px; padding-top: 6px; }
.sd-bars__col { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.sd-bars__bar { width: 100%; max-width: 30px; min-height: 2px; border-radius: 6px 6px 0 0;
  background: linear-gradient(180deg, #2E7CC4, #175A8C); transition: height .3s ease; }
.sd-bars__bar:hover { filter: brightness(1.15); }
.sd-bars__lbl { margin-top: 4px; font-size: 9.5px; color: #94A3B8; }

.sd-top { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px dashed #F1F5F9; }
.sd-top__rank { width: 24px; height: 24px; border-radius: 8px; flex-shrink: 0; font-size: 11.5px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center; background: #F1F5F9; color: #64748B; margin-top: 2px; }
.sd-top__body { flex: 1; min-width: 0; }
.sd-top__line { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; }
.sd-top__name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sd-top__track { height: 5px; border-radius: 4px; background: #F1F5F9; overflow: hidden; margin-top: 4px; }
.sd-top__bar { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #2E7CC4, #175A8C); }
.sd-top__meta { font-size: 10.5px; color: #94A3B8; margin-top: 2px; }

.sd-detail { display: flex; justify-content: space-between; gap: 12px; padding: 9px 4px; border-bottom: 1px dashed #F1F5F9; font-size: 13px; }
.sd-detail__lbl { color: #64748B; display: flex; align-items: center; }
.sd-detail__val { font-weight: 600; color: #0F172A; text-align: end; }
.prod-link { color: var(--q-primary); cursor: pointer; }
.prod-link:hover { text-decoration: underline; }
</style>
