<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="receipt_long" controlRoomButton="false" class="q-mt-xs">{{ $t('ReceiptDesigner') }}</m-header>
        </div>

        <div v-if="loading" class="col-12 text-center q-pa-xl"><q-spinner size="42px" color="primary" /></div>

        <template v-else>
          <!-- ── controls ─────────────────────────────────────── -->
          <div class="col-12 col-md-7">
            <q-card flat bordered class="rd-card">
              <div class="rd-card__head"><q-icon name="tune" size="18px" class="q-mr-xs" />{{ $t('ReceiptHeader') }}</div>
              <div class="q-pa-md row q-col-gutter-sm">
                <div class="col-12 col-sm-6">
                  <q-input outlined dense v-model="form.header_name" :label="$t('ShopName')"
                    :placeholder="company.name_en" clearable>
                    <template #prepend><q-icon name="storefront" color="primary" /></template>
                  </q-input>
                </div>
                <div class="col-12 col-sm-6">
                  <q-input outlined dense v-model="form.header_name_fa" :label="$t('ShopNameFa')"
                    :placeholder="company.name_fa" clearable>
                    <template #prepend><q-icon name="translate" color="primary" /></template>
                  </q-input>
                </div>
                <div class="col-12">
                  <q-input outlined dense v-model="form.tagline" :label="$t('Tagline')" clearable>
                    <template #prepend><q-icon name="short_text" color="primary" /></template>
                  </q-input>
                </div>
                <div class="col-12 col-sm-7">
                  <q-input outlined dense v-model="form.address" :label="$t('Address')"
                    :placeholder="company.address" clearable />
                </div>
                <div class="col-12 col-sm-5">
                  <q-input outlined dense v-model="form.phone" :label="$t('Phone')"
                    :placeholder="company.phone" clearable />
                </div>
              </div>
            </q-card>

            <q-card flat bordered class="rd-card q-mt-sm">
              <div class="rd-card__head"><q-icon name="checklist" size="18px" class="q-mr-xs" />{{ $t('WhatToPrint') }}</div>
              <div class="q-pa-md row q-col-gutter-x-md">
                <div v-for="t in TOGGLES" :key="t.key" class="col-12 col-sm-6">
                  <q-toggle v-model="form[t.key]" :label="$t(t.label)" color="primary" dense class="rd-toggle" />
                </div>
              </div>
            </q-card>

            <q-card flat bordered class="rd-card q-mt-sm">
              <div class="rd-card__head"><q-icon name="straighten" size="18px" class="q-mr-xs" />{{ $t('PaperAndText') }}</div>
              <div class="q-pa-md row q-col-gutter-md items-center">
                <div class="col-12 col-sm-5">
                  <div class="rd-label">{{ $t('PaperWidth') }}</div>
                  <q-btn-toggle v-model="form.paper" no-caps unelevated dense spread
                    toggle-color="primary" color="grey-3" text-color="grey-8"
                    :options="[{ label: '58 mm', value: '58mm' }, { label: '80 mm', value: '80mm' }]" />
                </div>
                <div class="col-12 col-sm-7">
                  <div class="rd-label">{{ $t('TextSize') }} — {{ form.font_size }} px</div>
                  <q-slider v-model="form.font_size" :min="9" :max="18" :step="1" markers label color="primary" />
                </div>
              </div>
            </q-card>

            <q-card flat bordered class="rd-card q-mt-sm">
              <div class="rd-card__head"><q-icon name="notes" size="18px" class="q-mr-xs" />{{ $t('ReceiptFooter') }}</div>
              <div class="q-pa-md row q-col-gutter-sm">
                <div class="col-12 col-sm-6">
                  <q-input outlined dense v-model="form.footer" :label="$t('ThankYouLine')" clearable />
                </div>
                <div class="col-12 col-sm-6">
                  <q-input outlined dense v-model="form.footer_fa" :label="$t('ThankYouLineFa')" clearable />
                </div>
                <div class="col-12">
                  <q-input outlined dense v-model="form.note" type="textarea" autogrow
                    :label="$t('SmallPrint')" :hint="$t('SmallPrintHint')" clearable />
                </div>
              </div>
            </q-card>

            <div class="row items-center q-mt-sm q-gutter-sm">
              <ac-btn icon="save" color="primary" :loading="saving" @click="save">{{ $t('Save') }}</ac-btn>
              <ac-btn icon="print" color="blue-grey-9" flat @click="printSample">{{ $t('PrintSample') }}</ac-btn>
              <ac-btn icon="restart_alt" color="grey-7" flat @click="resetAll">{{ $t('ResetToDefault') }}</ac-btn>
            </div>
          </div>

          <!-- ── live preview ─────────────────────────────────── -->
          <div class="col-12 col-md-5">
            <q-card flat bordered class="rd-card rd-sticky">
              <div class="rd-card__head">
                <q-icon name="visibility" size="18px" class="q-mr-xs" />{{ $t('LivePreview') }}
                <q-space />
                <q-badge color="blue-grey-2" text-color="blue-grey-9">{{ form.paper }}</q-badge>
              </div>
              <div class="rd-stage">
                <div class="rd-paper" :class="`rd-paper--${form.paper}`" :style="{ fontSize: form.font_size + 'px' }">
                  <div v-if="form.show_logo" class="rd-logo"><brand-mark size="34" /></div>
                  <div class="rd-name">{{ form.header_name || company.name_en }}</div>
                  <div v-if="form.header_name_fa || company.name_fa" class="rd-name rd-name--fa">
                    {{ form.header_name_fa || company.name_fa }}
                  </div>
                  <div v-if="form.tagline" class="rd-tag">{{ form.tagline }}</div>
                  <div v-if="form.address || company.address" class="rd-tag">{{ form.address || company.address }}</div>
                  <div v-if="form.phone || company.phone" class="rd-tag">{{ form.phone || company.phone }}</div>

                  <div class="rd-rule"></div>
                  <div class="rd-meta">
                    <div><span>{{ $t('Invoice') }}</span><b>INV-000123</b></div>
                    <div><span>{{ $t('Date') }}</span><b>{{ today }}</b></div>
                    <div v-if="form.show_cashier"><span>{{ $t('Cashier') }}</span><b>Counter 1</b></div>
                    <div v-if="form.show_counter"><span>{{ $t('Counter') }}</span><b>Counter 1</b></div>
                    <div v-if="form.show_customer"><span>{{ $t('Customer') }}</span><b>Fatima Ahmadi</b></div>
                  </div>

                  <div class="rd-rule"></div>
                  <table class="rd-items">
                    <thead><tr><th class="l">{{ $t('Item') }}</th><th class="c">{{ $t('Qty') }}</th><th class="r">{{ $t('Total') }}</th></tr></thead>
                    <tbody>
                      <tr v-for="it in SAMPLE" :key="it.name">
                        <td class="l">
                          {{ it.name }}
                          <div v-if="form.show_unit_price" class="rd-unit">{{ it.price }} × {{ it.qty }}</div>
                        </td>
                        <td class="c">{{ it.qty }}</td>
                        <td class="r">{{ (it.price * it.qty).toLocaleString() }}</td>
                      </tr>
                    </tbody>
                  </table>

                  <div class="rd-rule"></div>
                  <div class="rd-tot"><span>{{ $t('Subtotal') }}</span><span>{{ sample.subtotal.toLocaleString() }}</span></div>
                  <div v-if="form.show_discount_line" class="rd-tot"><span>{{ $t('Discount') }}</span><span>- 20</span></div>
                  <div v-if="form.show_tax_line" class="rd-tot"><span>{{ $t('Tax') }}</span><span>0</span></div>
                  <div class="rd-tot rd-tot--grand"><span>{{ $t('Total') }}</span><span>{{ (sample.subtotal - 20).toLocaleString() }} AFN</span></div>

                  <template v-if="form.show_payment_lines">
                    <div class="rd-rule"></div>
                    <div class="rd-tot"><span>{{ $t('Cash') }}</span><span>{{ (sample.subtotal - 20).toLocaleString() }}</span></div>
                    <div class="rd-tot"><span>{{ $t('Change') }}</span><span>0</span></div>
                  </template>

                  <div v-if="form.show_loyalty" class="rd-loyal">
                    <q-icon name="workspace_premium" size="12px" /> Gold · 2,146 {{ $t('LoyaltyPoints') }}
                  </div>

                  <div v-if="form.show_barcode" class="rd-barcode">
                    <span v-for="n in 46" :key="n" :style="{ width: (n % 4 ? 1 : 2) + 'px', opacity: n % 3 ? 1 : 0.25 }"></span>
                    <div class="rd-barcode__txt">INV-000123</div>
                  </div>

                  <div class="rd-foot">{{ form.footer }}</div>
                  <div v-if="form.footer_fa" class="rd-foot rd-foot--fa">{{ form.footer_fa }}</div>
                  <div v-if="form.note" class="rd-note">{{ form.note }}</div>
                </div>
              </div>
            </q-card>
          </div>
        </template>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
/**
 * The receipt layout, edited with a live 80 mm preview beside the controls.
 * Everything here is stored per company, so the owner can change what a
 * customer is handed without touching code.
 */
import { ref, reactive, computed, onMounted, getCurrentInstance } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'
import BrandMark from '@/components/general/BrandMark.vue'

const { proxy } = getCurrentInstance()
const loading = ref(true)
const saving = ref(false)
const company = ref({})
const defaults = ref({})
const form = reactive({})

const TOGGLES = [
  { key: 'show_logo', label: 'PrintLogo' },
  { key: 'show_cashier', label: 'PrintCashier' },
  { key: 'show_counter', label: 'PrintCounter' },
  { key: 'show_customer', label: 'PrintCustomer' },
  { key: 'show_unit_price', label: 'PrintUnitPrice' },
  { key: 'show_discount_line', label: 'PrintDiscountLine' },
  { key: 'show_tax_line', label: 'PrintTaxLine' },
  { key: 'show_payment_lines', label: 'PrintPaymentLines' },
  { key: 'show_loyalty', label: 'PrintLoyalty' },
  { key: 'show_barcode', label: 'PrintBarcode' },
  { key: 'show_branch', label: 'ShowBranch' },
  { key: 'show_item_count', label: 'ShowItemCount' },
  { key: 'show_saving', label: 'ShowSaving' },
  // Off means the cashier presses Print; on means the bill just appears.
  { key: 'auto_print', label: 'AutoPrint', hint: 'AutoPrintHint' },
]

const SAMPLE = [
  { name: 'Sugar 1kg', qty: 2, price: 60 },
  { name: 'Cooking Oil 1L', qty: 1, price: 120 },
  { name: 'Instant Noodles', qty: 4, price: 20 },
]
const sample = computed(() => ({ subtotal: SAMPLE.reduce((s, i) => s + i.price * i.qty, 0) }))
const today = new Date().toLocaleDateString()

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/settings/receipt')
    defaults.value = data.defaults || {}
    company.value = data.company || {}
    Object.assign(form, data.settings || {})
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Could not load the receipt layout' })
  } finally { loading.value = false }
}
onMounted(load)

async function save () {
  saving.value = true
  try {
    const { data } = await api.put('/settings/receipt', form)
    Object.assign(form, data.settings || {})
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: proxy.$t('Saved') })
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

function resetAll () {
  Object.assign(form, defaults.value)
}

/** Print exactly what the preview shows, on the chosen paper width. */
function printSample () {
  const paper = document.querySelector('.rd-paper')
  if (!paper) return
  const w = window.open('', '_blank', 'width=420,height=700')
  if (!w) return
  const css = Array.from(document.styleSheets)
    .flatMap(sheet => { try { return Array.from(sheet.cssRules).map(r => r.cssText) } catch { return [] } })
    .filter(t => t.includes('.rd-'))
    .join('\n')
  w.document.write(
    `<html><head><title>Receipt</title><style>
      body{margin:0;padding:8px;font-family:'Courier New',monospace;background:#fff}
      ${css}
      .rd-paper{box-shadow:none;border:0}
    </style></head><body>${paper.outerHTML}</body></html>`
  )
  w.document.close()
  w.focus()
  w.print()
}
</script>

<style scoped>
.rd-card { border-radius: 16px; }
.rd-card__head {
  font-size: 14px; font-weight: 800; color: #175A8C;
  display: flex; align-items: center; padding: 12px 14px 6px;
}
.rd-label { font-size: 11px; font-weight: 700; color: #94A3B8; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
.rd-toggle { font-size: 13px; }
.rd-sticky { position: sticky; top: 66px; }

/* ── the preview stage ── */
.rd-stage {
  padding: 16px;
  background: repeating-linear-gradient(45deg, #EEF2F6, #EEF2F6 10px, #E7ECF3 10px, #E7ECF3 20px);
  display: flex; justify-content: center;
  border-radius: 0 0 16px 16px;
}
.rd-paper {
  background: #fff;
  color: #111;
  font-family: 'Courier New', ui-monospace, monospace;
  padding: 12px 10px 16px;
  box-shadow: 0 8px 22px -12px rgba(15, 23, 42, .45);
  line-height: 1.35;
}
.rd-paper--58mm { width: 200px; }
.rd-paper--80mm { width: 280px; }

.rd-logo { text-align: center; margin-bottom: 4px; }
.rd-name { text-align: center; font-weight: 700; letter-spacing: .02em; }
.rd-name--fa { font-family: inherit; font-weight: 600; }
.rd-tag { text-align: center; font-size: .82em; color: #444; }
.rd-rule { border-top: 1px dashed #999; margin: 7px 0; }

.rd-meta { font-size: .82em; }
.rd-meta div { display: flex; justify-content: space-between; gap: 8px; }
.rd-meta span { color: #555; }

.rd-items { width: 100%; border-collapse: collapse; font-size: .86em; }
.rd-items th { font-weight: 700; padding-bottom: 3px; border-bottom: 1px solid #bbb; }
.rd-items td { padding: 3px 0; vertical-align: top; }
.rd-items .l { text-align: start; }
.rd-items .c { text-align: center; width: 30px; }
.rd-items .r { text-align: end; white-space: nowrap; }
.rd-unit { font-size: .82em; color: #666; }

.rd-tot { display: flex; justify-content: space-between; font-size: .86em; padding: 1px 0; }
.rd-tot--grand { font-weight: 800; font-size: 1.04em; border-top: 1px solid #bbb; margin-top: 3px; padding-top: 4px; }

.rd-loyal {
  margin-top: 7px; padding: 4px 0;
  text-align: center; font-size: .8em; font-weight: 700;
  border-top: 1px dashed #999; border-bottom: 1px dashed #999;
}

.rd-barcode { margin-top: 10px; text-align: center; }
.rd-barcode span { display: inline-block; height: 34px; background: #111; margin-inline-end: 1px; vertical-align: bottom; }
.rd-barcode__txt { font-size: .74em; letter-spacing: .18em; margin-top: 2px; }

.rd-foot { margin-top: 9px; text-align: center; font-size: .86em; font-weight: 700; }
.rd-foot--fa { font-weight: 600; }
.rd-note { margin-top: 5px; text-align: center; font-size: .76em; color: #555; white-space: pre-line; }
</style>
