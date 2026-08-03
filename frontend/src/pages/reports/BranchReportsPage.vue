<template>
  <q-page class="br-page q-pa-md">
    <!-- Hero Section -->
    <div class="br-hero q-mb-md">
      <div>
        <div class="br-hero__eyebrow"><q-icon name="store" size="15px" /> {{ $t('BranchAnalytics') }}</div>
        <div class="br-hero__title">{{ selectedBranch?.name || $t('SelectBranch') }}</div>
        <div class="br-hero__sub">{{ $t('PerformanceMetrics') }}</div>
      </div>
      <q-space />
      <q-select outlined dense :options="branches" option-value="id" option-label="name"
        v-model="selectedBranchId" @update:model-value="load" style="width:200px"
        :label="$t('Branch')" />
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="primary" size="40px" /></div>

    <template v-else-if="report">
      <!-- KPI Grid -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="br-kpi">
            <div class="br-kpi__icon">📊</div>
            <div class="br-kpi__label">{{ $t('Orders') }}</div>
            <div class="br-kpi__value">{{ report.total_orders || 0 }}</div>
            <div class="br-kpi__sub">{{ report.days_with_sales || 0 }} {{ $t('DaysActive') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="br-kpi br-kpi--gold">
            <div class="br-kpi__icon">💰</div>
            <div class="br-kpi__label">{{ $t('TotalRevenue') }}</div>
            <div class="br-kpi__value">{{ fmt(report.total_revenue) }}</div>
            <div class="br-kpi__sub">{{ fmt(report.average_order_value) }} {{ $t('AvgTicket') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="br-kpi br-kpi--success">
            <div class="br-kpi__icon">📈</div>
            <div class="br-kpi__label">{{ $t('GrossProfit') }}</div>
            <div class="br-kpi__value">{{ fmt(report.gross_profit) }}</div>
            <div class="br-kpi__sub">{{ (report.gross_margin || 0).toFixed(1) }}% {{ $t('Margin') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="br-kpi br-kpi--info">
            <div class="br-kpi__icon">🎯</div>
            <div class="br-kpi__label">{{ $t('NetProfit') }}</div>
            <div class="br-kpi__value">{{ fmt(report.net_profit) }}</div>
            <div class="br-kpi__sub">{{ (report.net_margin || 0).toFixed(1) }}% {{ $t('Margin') }}</div>
          </div>
        </div>
      </div>

      <!-- Revenue vs Expenses -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-lg-6">
          <q-card flat bordered class="br-card">
            <div class="br-card__head"><q-icon name="trending_up" size="16px" /> {{ $t('FinancialBreakdown') }}</div>
            <div class="br-financial">
              <div class="br-fin-row">
                <span>{{ $t('Revenue') }}</span>
                <span class="text-weight-bold text-green-7">{{ fmt(report.total_revenue) }}</span>
              </div>
              <div class="br-fin-row">
                <span>{{ $t('Discount') }}</span>
                <span class="text-weight-bold text-orange-7">-{{ fmt(report.total_discount) }}</span>
              </div>
              <div class="br-fin-row br-fin-row--divider">
                <span>{{ $t('Gross') }}</span>
                <span class="text-weight-bold">{{ fmt(report.total_revenue - (report.total_discount || 0)) }}</span>
              </div>
              <div class="br-fin-row">
                <span>{{ $t('COGS') }}</span>
                <span class="text-weight-bold text-red-7">-{{ fmt(report.cogs) }}</span>
              </div>
              <div class="br-fin-row">
                <span>{{ $t('TotalExpenses') }}</span>
                <span class="text-weight-bold text-red-7">-{{ fmt(report.total_expenses) }}</span>
              </div>
              <div class="br-fin-row br-fin-row--total">
                <span>{{ $t('NetProfit') }}</span>
                <span class="text-weight-bold text-blue-7" :class="report.net_profit >= 0 ? 'text-green-7' : 'text-red-7'">
                  {{ fmt(report.net_profit) }}
                </span>
              </div>
            </div>
          </q-card>
        </div>

        <div class="col-12 col-lg-6">
          <q-card flat bordered class="br-card">
            <div class="br-card__head"><q-icon name="payments" size="16px" /> {{ $t('PaymentMethodBreakdown') }}</div>
            <div v-if="!report.payment_methods || !report.payment_methods.length" class="text-center text-grey-5 q-pa-md">
              {{ $t('NoData') }}
            </div>
            <div v-else class="br-payments">
              <div v-for="pm in report.payment_methods" :key="pm.method" class="br-pay-item">
                <div class="row items-center q-mb-xs">
                  <span class="br-pay-label">{{ pm.method }}</span>
                  <q-space />
                  <span class="text-weight-bold">{{ fmt(pm.amount) }}</span>
                </div>
                <div class="br-pay-bar">
                  <div class="br-pay-fill" :style="`width:${(pm.amount / (report.total_revenue || 1)) * 100}%`"></div>
                </div>
              </div>
            </div>
          </q-card>
        </div>
      </div>

      <!-- End of Day Summary -->
      <q-card v-if="report.end_of_day_summary" flat bordered class="br-card">
        <div class="br-card__head"><q-icon name="receipt_long" size="16px" /> {{ $t('EndOfDaySummary') }}</div>
        <q-markup-table flat dense class="br-eod-table">
          <tbody>
            <tr>
              <td class="text-weight-bold">{{ $t('TotalBatches') }}</td>
              <td class="text-right">{{ report.end_of_day_summary.total_batches || 0 }}</td>
            </tr>
            <tr>
              <td class="text-weight-bold">{{ $t('AverageVariance') }}</td>
              <td class="text-right" :class="(report.end_of_day_summary.avg_variance || 0) >= 0 ? 'text-green-7' : 'text-red-7'">
                {{ fmt(report.end_of_day_summary.avg_variance) }}
              </td>
            </tr>
            <tr>
              <td class="text-weight-bold">{{ $t('BalancedDays') }}</td>
              <td class="text-right text-green-7">{{ report.end_of_day_summary.balanced_count || 0 }}</td>
            </tr>
            <tr>
              <td class="text-weight-bold">{{ $t('VarianceDays') }}</td>
              <td class="text-right text-orange-7">{{ report.end_of_day_summary.variance_count || 0 }}</td>
            </tr>
          </tbody>
        </q-markup-table>
      </q-card>
    </template>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/boot/axios'

const branches = ref([])
const selectedBranchId = ref(null)
const selectedBranch = computed(() => branches.value.find(b => b.id === selectedBranchId.value))
const report = ref(null)
const loading = ref(false)

const fmt = (v) => Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 2, minimumFractionDigits: 0 })

async function loadBranches() {
  try {
    const { data } = await api.get('/branches')
    branches.value = data
    if (data.length) {
      selectedBranchId.value = data[0].id
      await load()
    }
  } catch (e) {
    console.error('Failed to load branches:', e)
  }
}

async function load() {
  if (!selectedBranchId.value) return
  loading.value = true
  try {
    const { data } = await api.get(`/reports/branch/${selectedBranchId.value}`)
    report.value = data
  } catch (e) {
    console.error('Failed to load report:', e)
  } finally {
    loading.value = false
  }
}

onMounted(loadBranches)
</script>

<style scoped>
.br-page {
  background: #f5f7fa;
}

.br-hero {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 12px;
  padding: 20px;
  color: white;
  display: flex;
  align-items: center;
  gap: 20px;
}

.br-hero__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1px;
  text-transform: uppercase;
  opacity: 0.8;
}

.br-hero__title {
  font-size: 24px;
  font-weight: 900;
  margin-top: 4px;
}

.br-hero__sub {
  font-size: 13px;
  opacity: 0.9;
  margin-top: 2px;
}

.br-kpi {
  background: white;
  border: 1px solid #e0e7ff;
  border-radius: 12px;
  padding: 16px;
  text-align: center;
  transition: all 0.2s ease;
}

.br-kpi:hover {
  box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
  transform: translateY(-2px);
}

.br-kpi--gold {
  background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
  border-color: #f59e0b;
}

.br-kpi--success {
  background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
  border-color: #16a34a;
}

.br-kpi--info {
  background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
  border-color: #0284c7;
}

.br-kpi__icon {
  font-size: 24px;
  margin-bottom: 8px;
}

.br-kpi__label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.br-kpi__value {
  font-size: 24px;
  font-weight: 900;
  color: #1f2937;
  margin: 6px 0;
}

.br-kpi__sub {
  font-size: 12px;
  color: #9ca3af;
}

.br-card {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
}

.br-card__head {
  font-size: 14px;
  font-weight: 700;
  padding: 16px;
  border-bottom: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #1f2937;
}

.br-financial {
  padding: 16px;
}

.br-fin-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  font-size: 14px;
  border-bottom: 1px solid #f3f4f6;
}

.br-fin-row--divider {
  border-top: 2px solid #e5e7eb;
  border-bottom: 2px solid #e5e7eb;
  padding: 12px 0;
  font-weight: 600;
}

.br-fin-row--total {
  border: none;
  margin-top: 8px;
  padding: 12px 0;
  font-size: 16px;
  font-weight: 800;
  border-top: 2px solid #e5e7eb;
}

.br-payments {
  padding: 16px;
}

.br-pay-item {
  margin-bottom: 16px;
}

.br-pay-label {
  font-weight: 600;
  color: #374151;
}

.br-pay-bar {
  height: 6px;
  background: #f3f4f6;
  border-radius: 3px;
  overflow: hidden;
}

.br-pay-fill {
  height: 100%;
  background: linear-gradient(90deg, #667eea, #764ba2);
  transition: width 0.3s ease;
}

.br-eod-table {
  width: 100%;
}

.br-eod-table td {
  padding: 12px 16px;
}

.br-eod-table tr:not(:last-child) {
  border-bottom: 1px solid #f3f4f6;
}
</style>
