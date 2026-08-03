<template>
  <q-dialog v-model="open" @keydown.escape="open = false">
    <q-card class="po-card">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6">{{ $t('PrintOptions') }}</div>
        <q-space />
        <q-btn icon="close" flat round dense v-close-popup />
      </q-card-section>

      <q-separator />

      <q-card-section class="q-gutter-md">
        <!-- Number of Copies -->
        <div class="po-group">
          <div class="po-label">{{ $t('NumberOfCopies') }}</div>
          <div class="row items-center gap-2">
            <q-btn flat round dense icon="remove" @click="copies = Math.max(1, copies - 1)" :disable="copies <= 1" />
            <q-input outlined dense v-model.number="copies" type="number" min="1" max="10"
              class="po-input" style="max-width: 100px" @keydown.enter="doPrint" />
            <q-btn flat round dense icon="add" @click="copies = Math.min(10, copies + 1)" :disable="copies >= 10" />
          </div>
        </div>

        <!-- Paper Size -->
        <div class="po-group">
          <div class="po-label">{{ $t('PaperSize') }}</div>
          <q-option-group v-model="paperSize" :options="paperOptions" color="primary" />
        </div>

        <!-- Print Mode -->
        <div class="po-group">
          <div class="po-label">{{ $t('PrintMode') }}</div>
          <q-option-group v-model="printMode" :options="printModeOptions" color="primary" />
        </div>

        <!-- Printer Type Info -->
        <div class="po-info">
          <q-icon name="info" size="20px" color="blue-6" />
          <span v-if="printMode === 'thermal'" class="q-ml-sm">{{ $t('ThermalPrinterNote') }}</span>
          <span v-else class="q-ml-sm">{{ $t('StandardPrinterNote') }}</span>
        </div>
      </q-card-section>

      <q-separator />

      <q-card-actions class="q-pa-md">
        <q-btn flat no-caps :label="$t('Cancel')" v-close-popup />
        <q-space />
        <q-btn unelevated no-caps color="primary" icon="print" :label="$t('Print')"
          @click="doPrint" :loading="printing" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { ref } from 'vue'

const open = ref(false)
const copies = ref(1)
const paperSize = ref('80mm')
const printMode = ref('thermal')
const printing = ref(false)

const paperOptions = [
  { label: '80mm (Thermal)', value: '80mm' },
  { label: 'A4 (Standard)', value: 'a4' },
  { label: '58mm (Compact)', value: '58mm' },
]

const printModeOptions = [
  { label: 'Thermal Printer', value: 'thermal' },
  { label: 'Standard Printer', value: 'standard' },
]

const onPrint = ref(null)

function doPrint () {
  if (onPrint.value) {
    printing.value = true
    onPrint.value({
      copies: copies.value,
      paperSize: paperSize.value,
      printMode: printMode.value,
    }).finally(() => {
      printing.value = false
      open.value = false
    })
  }
}

function show (callback) {
  onPrint.value = callback
  open.value = true
}

defineExpose({ show })
</script>

<style scoped>
.po-card {
  min-width: 360px;
}

.po-group {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.po-label {
  font-weight: 600;
  font-size: 13px;
  color: #1f2937;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.po-input {
  flex: 0 1 auto;
}

.po-info {
  background: #dbeafe;
  border: 1px solid #93c5fd;
  border-radius: 8px;
  padding: 12px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 13px;
  color: #1e40af;
}
</style>
