<template>
  <teleport to="body">
    <div v-if="data" class="receipt-print">
      <div class="rcp">
        <div class="rcp__head">
          <div class="rcp__store">{{ data.store }}</div>
          <div class="rcp__sub">{{ data.type === 'z' ? $t('ZReport') : $t('XReport') }} · #{{ data.shiftId }}</div>
        </div>
        <div class="rcp__meta">
          <div>{{ $t('Cashier') }}: {{ data.cashier || '—' }}</div>
          <div>{{ $t('Opened') }}: {{ fmtDate(data.openedAt) }}</div>
          <div v-if="data.closedAt">{{ $t('Closed') }}: {{ fmtDate(data.closedAt) }}</div>
          <div v-else>{{ $t('LiveSnapshot') }}: {{ now }}</div>
        </div>
        <div class="rcp__rule"></div>
        <div class="rcp__tot"><span>{{ $t('OpeningFloat') }}</span><span>{{ fmt(data.openingFloat) }}</span></div>
        <div class="rcp__tot"><span>{{ $t('CashSales') }}</span><span>{{ fmt(data.cashSales) }}</span></div>
        <div class="rcp__tot"><span>{{ $t('CardSales') }}</span><span>{{ fmt(data.cardSales) }}</span></div>
        <div class="rcp__tot"><span>{{ $t('MobileSales') }}</span><span>{{ fmt(data.mobileSales) }}</span></div>
        <div class="rcp__tot"><span>{{ $t('CashIn') }}</span><span>+ {{ fmt(data.cashIn) }}</span></div>
        <div class="rcp__tot"><span>{{ $t('CashOut') }}</span><span>- {{ fmt(data.cashOut) }}</span></div>
        <div class="rcp__rule"></div>
        <div class="rcp__tot rcp__tot--grand"><span>{{ $t('ExpectedInDrawer') }}</span><span>{{ fmt(data.expectedCash) }}</span></div>
        <template v-if="data.type === 'z'">
          <div class="rcp__tot"><span>{{ $t('CountedCash') }}</span><span>{{ fmt(data.countedCash) }}</span></div>
          <div class="rcp__tot rcp__tot--grand"><span>{{ data.variance == 0 ? $t('Balanced') : (data.variance > 0 ? $t('Over') : $t('Short')) }}</span><span>{{ fmt(Math.abs(data.variance)) }}</span></div>
        </template>
        <div class="rcp__rule"></div>
        <div class="rcp__tot"><span>{{ $t('Orders') }}</span><span>{{ data.orders }}</span></div>
        <div class="rcp__tot"><span>{{ $t('TotalSales') }}</span><span>{{ fmt(data.totalSales) }}</span></div>
        <div class="rcp__foot">{{ $t('EndOfReport') }}</div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { defineProps, defineExpose } from 'vue'

defineProps({ data: { type: Object, default: null } })

const fmt = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })
const fmtDate = (v) => v ? new Date(v).toLocaleString() : ''
const now = new Date().toLocaleString()

function print () { setTimeout(() => window.print(), 100) }
defineExpose({ print })
</script>

<style>
/* Reuses the .receipt-print / .rcp print styles defined by ReceiptPrint.vue
   (loaded app-wide). No extra styles needed here. */
</style>
