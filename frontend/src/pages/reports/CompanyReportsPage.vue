<template>
  <q-page class="cr-page q-pa-md">
    <!-- Hero Section -->
    <div class="cr-hero q-mb-md">
      <div>
        <div class="cr-hero__eyebrow"><q-icon name="business" size="15px" /> {{ $t('CompanyAnalytics') }}</div>
        <div class="cr-hero__title">{{ $t('CompanyPerformance') }}</div>
        <div class="cr-hero__sub">{{ $t('EntireOrganizationMetrics') }}</div>
      </div>
      <q-space />
      <q-btn flat round icon="refresh" @click="load" :loading="loading" />
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="primary" size="40px" /></div>

    <template v-else-if="report">
      <!-- Executive Summary KPIs -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-sm-6 col-md-4">
          <div class="cr-kpi cr-kpi--revenue">
            <div class="cr-kpi__label">{{ $t('TotalRevenue') }}</div>
            <div class="cr-kpi__value">{{ fmt(report.total_revenue) }}</div>
            <div class="cr-kpi__trend">{{ $t('AllBranches') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
          <div class="cr-kpi cr-kpi--profit">
            <div class="cr-kpi__label">{{ $t('NetProfit') }}</div>
            <div class="cr-kpi__value" :class="report.net_profit >= 0 ? 'text-green-7' : 'text-red-7'">
              {{ fmt(report.net_profit) }}
            </div>
            <div class="cr-kpi__trend">{{ (report.net_margin || 0).toFixed(1) }}% {{ $t('Margin') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
          <div class="cr-kpi cr-kpi--orders">
            <div class="cr-kpi__label">{{ $t('TotalOrders') }}</div>
            <div class="cr-kpi__value">{{ report.total_orders || 0 }}</div>
            <div class="cr-kpi__trend">{{ fmt(report.average_order_value) }} {{ $t('AvgTicket') }}</div>
          </div>
        </div>
      </div>

      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="cr-stat">
            <div class="cr-stat__icon" style="background:#e0e7ff">📊</div>
            <div class="cr-stat__value">{{ report.days_with_sales || 0 }}</div>
            <div class="cr-stat__label">{{ $t('ActiveDays') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="cr-stat">
            <div class="cr-stat__icon" style="background:#dcfce7">💰</div>
            <div class="cr-stat__value">{{ fmt(report.gross_profit) }}</div>
            <div class="cr-stat__label">{{ $t('GrossProfit') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="cr-stat">
            <div class="cr-stat__icon" style="background:#fee2e2">🛍️</div>
            <div class="cr-stat__value">{{ fmt(report.total_discount) }}</div>
            <div class="cr-stat__label">{{ $t('TotalDiscounts') }}</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="cr-stat">
            <div class="cr-stat__icon" style="background:#fef3c7">📈</div>
            <div class="cr-stat__value">{{ (report.gross_margin || 0).toFixed(1) }}%</div>
            <div class="cr-stat__label">{{ $t('GrossMargin') }}</div>
          </div>
        </div>
      </div>

      <!-- Financial Summary -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-lg-6">
          <q-card flat bordered class="cr-card">
            <div class="cr-card__head"><q-icon name="account_balance" size="16px" /> {{ $t('FinancialStatement') }}</div>
            <div class="cr-financial q-pa-md">
              <div class="cr-fin-row">
                <span>{{ $t('Revenue') }}</span>
                <span class="text-weight-bold text-green-7">{{ fmt(report.total_revenue) }}</span>
              </div>
              <div class="cr-fin-row">
                <span>{{ $t('Discounts') }}</span>
                <span class="text-weight-bold text-orange-7">-{{ fmt(report.total_discount) }}</span>
              </div>
              <div class="cr-fin-row cr-fin-row--sep">
                <span>{{ $t('GrossRevenue') }}</span>
                <span class="text-weight-bold">{{ fmt((report.total_revenue || 0) - (report.total_discount || 0)) }}</span>
              </div>
              <div class="cr-fin-row">
                <span>{{ $t('COGS') }}</span>
                <span class="text-weight-bold text-red-7">-{{ fmt(report.cogs) }}</span>
              </div>
              <div class="cr-fin-row">
                <span>{{ $t('OperatingExpenses') }}</span>
                <span class="text-weight-bold text-red-7">-{{ fmt(report.total_expenses) }}</span>
              </div>
              <div class="cr-fin-row cr-fin-row--total">
                <span>{{ $t('NetIncome') }}</span>
                <span class="text-weight-bold" :class="report.net_profit >= 0 ? 'text-green-7' : 'text-red-7'">
                  {{ fmt(report.net_profit) }}
                </span>
              </div>
            </div>
          </q-card>
        </div>

        <div class="col-12 col-lg-6">
          <q-card flat bordered class="cr-card">
            <div class="cr-card__head"><q-icon name="pie_chart" size="16px" /> {{ $t('ProfitMarginAnalysis') }}</div>
            <div class="cr-margins q-pa-md">
              <div class="cr-margin-item">
                <div class="row items-center q-mb-xs">
                  <span class="text-weight-bold">{{ $t('GrossMargin') }}</span>
                  <q-space />
                  <span class="text-weight-bold">{{ (report.gross_margin || 0).toFixed(1) }}%</span>
                </div>
                <div class="cr-margin-bar">
                  <div class="cr-margin-fill" :style="`width:${Math.min(100, (report.gross_margin || 0))}%;background:#10b981`"></div>
                </div>
              </div>
              <div class="cr-margin-item">
                <div class="row items-center q-mb-xs">
                  <span class="text-weight-bold">{{ $t('NetMargin') }}</span>
                  <q-space />
                  <span class="text-weight-bold" :class="report.net_margin >= 0 ? 'text-green-7' : 'text-red-7'">
                    {{ (report.net_margin || 0).toFixed(1) }}%
                  </span>
                </div>
                <div class="cr-margin-bar">
                  <div class="cr-margin-fill" :style="`width:${Math.abs(Math.min(100, (report.net_margin || 0)))}%;background:${report.net_margin >= 0 ? '#3b82f6' : '#ef4444'}`"></div>
                </div>
              </div>
            </div>
          </q-card>
        </div>
      </div>

      <!-- Payment Methods Breakdown -->
      <q-card v-if="report.payment_methods && report.payment_methods.length" flat bordered class="cr-card q-mb-md">
        <div class="cr-card__head"><q-icon name="credit_card" size="16px" /> {{ $t('PaymentMethodBreakdown') }}</div>
        <div class="cr-payments q-pa-md">
          <div v-for="pm in report.payment_methods" :key="pm.method" class="cr-payment-row">
            <div class="row items-center q-mb-xs">
              <span class="text-weight-bold">{{ pm.method }}</span>
              <q-space />
              <span class="text-weight-bold">{{ fmt(pm.amount) }}</span>
              <span class="q-ml-md text-grey-6">{{ ((pm.amount / (report.total_revenue || 1)) * 100).toFixed(1) }}%</span>
            </div>
            <div class="cr-payment-bar">
              <div class="cr-payment-fill" :style="`width:${(pm.amount / (report.total_revenue || 1)) * 100}%`"></div>
            </div>
          </div>
        </div>
      </q-card>

      <!-- End of Day Summary -->
      <q-card v-if="report.end_of_day_summary" flat bordered class="cr-card">
        <div class="cr-card__head"><q-icon name="check_circle" size="16px" /> {{ $t('EndOfDayReconciliation') }}</div>
        <q-markup-table flat dense class="cr-eod-table">
          <tbody>
            <tr>
              <td class="text-weight-bold w-1/3">{{ $t('TotalReports') }}</td>
              <td class="text-right">{{ report.end_of_day_summary.total_batches || 0 }}</td>
              <td></td>
            </tr>
            <tr>
              <td class="text-weight-bold">{{ $t('BalancedReports') }}</td>
              <td class="text-right text-green-7">{{ report.end_of_day_summary.balanced_count || 0 }}</td>
              <td class="text-right text-grey-6 text-sm">{{ report.end_of_day_summary.total_batches ? ((report.end_of_day_summary.balanced_count / report.end_of_day_summary.total_batches) * 100).toFixed(0) : 0 }}%</td>
            </tr>
            <tr>
              <td class="text-weight-bold">{{ $t('VarianceReports') }}</td>
              <td class="text-right text-orange-7">{{ report.end_of_day_summary.variance_count || 0 }}</td>
              <td class="text-right text-grey-6 text-sm">{{ report.end_of_day_summary.total_batches ? ((report.end_of_day_summary.variance_count / report.end_of_day_summary.total_batches) * 100).toFixed(0) : 0 }}%</td>
            </tr>
            <tr>
              <td class="text-weight-bold">{{ $t('AvgVariance') }}</td>
              <td class="text-right" :class="(report.end_of_day_summary.avg_variance || 0) >= 0 ? 'text-green-7' : 'text-red-7'">
                {{ fmt(report.end_of_day_summary.avg_variance) }}
              </td>
              <td></td>
            </tr>
          </tbody>
        </q-markup-table>
      </q-card>
    </template>
  </q-page>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/boot/axios'

const report = ref(null)
const loading = ref(false)

const fmt = (v) => Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 2, minimumFractionDigits: 0 })

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/reports/company')
    report.value = data
  } catch (e) {
    console.error('Failed to load company report:', e)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.cr-page {
  background: #f9fafb;
}

.cr-hero {
  background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
  border-radius: 12px;
  padding: 24px;
  color: white;
  display: flex;
  align-items: center;
  gap: 20px;
}

.cr-hero__eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  opacity: 0.85;
}

.cr-hero__title {
  font-size: 28px;
  font-weight: 900;
  margin-top: 6px;
  letter-spacing: -0.5px;
}

.cr-hero__sub {
  font-size: 13px;
  opacity: 0.9;
  margin-top: 4px;
}

.cr-kpi {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 20px;
  text-align: center;
  transition: all 0.2s ease;
}

.cr-kpi:hover {
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
  transform: translateY(-2px);
}

.cr-kpi--revenue {
  border-color: #10b981;
  background: linear-gradient(135deg, #ecfdf5 0%, #dbeafe 100%);
}

.cr-kpi--profit {
  border-color: #f59e0b;
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
}

.cr-kpi--orders {
  border-color: #8b5cf6;
  background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
}

.cr-kpi__label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.cr-kpi__value {
  font-size: 28px;
  font-weight: 900;
  color: #1f2937;
  margin: 8px 0;
}

.cr-kpi__trend {
  font-size: 12px;
  color: #9ca3af;
}

.cr-stat {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.cr-stat__icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  flex-shrink: 0;
}

.cr-stat__value {
  font-size: 20px;
  font-weight: 900;
  color: #1f2937;
}

.cr-stat__label {
  font-size: 12px;
  color: #9ca3af;
  font-weight: 500;
}

.cr-card {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
}

.cr-card__head {
  font-size: 14px;
  font-weight: 700;
  padding: 16px;
  border-bottom: 1px solid #f3f4f6;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #1f2937;
}

.cr-financial {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cr-fin-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  font-size: 14px;
  border-bottom: 1px solid #f3f4f6;
}

.cr-fin-row--sep {
  border-top: 2px solid #e5e7eb;
  border-bottom: 2px solid #e5e7eb;
  padding: 12px 0;
  font-weight: 600;
  background: #fafafa;
}

.cr-fin-row--total {
  border: none;
  margin-top: 8px;
  padding: 12px 0;
  font-size: 16px;
  font-weight: 800;
  border-top: 2px solid #e5e7eb;
  padding-top: 12px;
}

.cr-margins {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.cr-margin-item {
  flex: 1;
}

.cr-margin-bar {
  height: 8px;
  background: #f3f4f6;
  border-radius: 4px;
  overflow: hidden;
}

.cr-margin-fill {
  height: 100%;
  transition: width 0.3s ease;
  border-radius: 4px;
}

.cr-payments {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.cr-payment-row {
  flex: 1;
}

.cr-payment-bar {
  height: 6px;
  background: #f3f4f6;
  border-radius: 3px;
  overflow: hidden;
}

.cr-payment-fill {
  height: 100%;
  background: linear-gradient(90deg, #1e3c72, #2a5298);
  transition: width 0.3s ease;
}

.cr-eod-table {
  width: 100%;
}

.cr-eod-table td {
  padding: 12px 16px;
}

.cr-eod-table tr:not(:last-child) {
  border-bottom: 1px solid #f3f4f6;
}
</style>
