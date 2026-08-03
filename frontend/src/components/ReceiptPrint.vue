<template>
  <teleport to="body">
    <div v-if="sale" ref="root" class="receipt-print">
      <div class="rcp" :class="`rcp--${cfg.paper}`" :style="{ fontSize: cfg.font_size + 'px' }">
        <div class="rcp__head">
          <!-- The shop's mark. Inlined rather than fetched: a thermal print
               must never wait on (or fail because of) a network image. -->
          <img v-if="cfg.show_logo" class="rcp__logo" :src="logoSrc" alt="">
          <div class="rcp__store">{{ cfg.header_name || company }}</div>
          <div v-if="cfg.header_name_fa" class="rcp__store">{{ cfg.header_name_fa }}</div>
          <div v-if="cfg.tagline" class="rcp__sub">{{ cfg.tagline }}</div>
          <div v-if="cfg.address" class="rcp__sub">{{ cfg.address }}</div>
          <div v-if="cfg.phone" class="rcp__sub">{{ $t('Phone') }}: {{ cfg.phone }}</div>
          <div class="rcp__title">{{ $t('SalesReceipt') }}</div>
        </div>
        <div class="rcp__meta">
          <div class="rcp__metarow"><span>{{ $t('Invoice') }}</span><b>{{ sale.invoice_no }}</b></div>
          <div class="rcp__metarow"><span>{{ $t('Date') }}</span><span>{{ fmtDate(sale.sold_at) }}</span></div>
          <div class="rcp__metarow" v-if="cfg.show_branch && sale.branch"><span>{{ $t('Branch') }}</span><span>{{ sale.branch.name }}</span></div>
          <div class="rcp__metarow" v-if="cfg.show_counter && sale.counter"><span>{{ $t('Counter') }}</span><span>{{ sale.counter.name }}</span></div>
          <div class="rcp__metarow" v-if="cfg.show_cashier && sale.cashier"><span>{{ $t('Cashier') }}</span><span>{{ sale.cashier.name }}</span></div>
          <div class="rcp__metarow" v-if="cfg.show_customer && sale.customer">
            <span>{{ $t('Customer') }}</span>
            <span>{{ sale.customer.name }}<template v-if="sale.customer.phone"> · {{ sale.customer.phone }}</template></span>
          </div>
        </div>
        <div class="rcp__rule"></div>
        <table class="rcp__items">
          <thead>
            <tr><th class="l">{{ $t('Item') }}</th><th class="c">{{ $t('Qty') }}</th><th class="r">{{ $t('Total') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="(it, i) in (sale.items || [])" :key="i">
              <td class="l">{{ it.name }}<div v-if="cfg.show_unit_price" class="rcp__unit">{{ fmt(it.unit_price) }}</div></td>
              <td class="c">{{ num(it.qty) }}</td>
              <td class="r">{{ fmt(it.line_total) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="rcp__rule"></div>
        <div class="rcp__tot"><span>{{ $t('Subtotal') }}</span><span>{{ fmt(sale.subtotal) }}</span></div>
        <div class="rcp__tot" v-if="cfg.show_discount_line && Number(sale.discount) > 0"><span>{{ $t('Discount') }}</span><span>- {{ fmt(sale.discount) }}</span></div>
        <div class="rcp__tot" v-if="cfg.show_tax_line && Number(sale.tax) > 0"><span>{{ $t('Tax') }}</span><span>{{ fmt(sale.tax) }}</span></div>
        <div class="rcp__tot rcp__tot--grand"><span>{{ $t('Total') }}</span><span>{{ fmt(sale.total) }}</span></div>
        <!-- A quick check against what is in the bag before the customer walks off. -->
        <div class="rcp__count" v-if="cfg.show_item_count">
          {{ lineCount }} {{ $t('Items') }} · {{ num(unitCount) }} {{ $t('Units') }}
        </div>
        <template v-if="cfg.show_payment_lines">
          <div class="rcp__rule"></div>
          <div class="rcp__tot" v-for="(p, i) in (sale.payments || [])" :key="i"><span>{{ payLabel(p.method) }}</span><span>{{ fmt(p.amount) }}</span></div>
          <div class="rcp__tot"><span>{{ $t('Paid') }}</span><span>{{ fmt(sale.paid) }}</span></div>
          <div class="rcp__tot"><span>{{ $t('Change') }}</span><span>{{ fmt(sale.change_due) }}</span></div>
        </template>
        <!-- Saying what they saved is the cheapest loyalty there is. -->
        <div v-if="cfg.show_saving && savedTotal > 0" class="rcp__saved">
          {{ $t('YouSaved') }} {{ fmt(savedTotal) }}
        </div>
        <div v-if="cfg.show_loyalty && sale.customer?.loyalty?.tier_label" class="rcp__loyal">
          {{ $t(sale.customer.loyalty.tier_label) }} · {{ sale.customer.loyalty.points }} {{ $t('LoyaltyPoints') }}
        </div>
        <svg v-show="cfg.show_barcode" ref="bc" class="rcp__barcode"></svg>
        <div class="rcp__foot">{{ cfg.footer || $t('ThankYou') }}</div>
        <div v-if="cfg.footer_fa" class="rcp__foot">{{ cfg.footer_fa }}</div>
        <div v-if="cfg.note" class="rcp__note">{{ cfg.note }}</div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, getCurrentInstance } from 'vue'
import JsBarcode from 'jsbarcode'
import { api, assetUrl } from '@/boot/axios'
// Imported as source text, not as a URL: the mark becomes part of the print
// job itself, so it cannot arrive late or fail to load at the moment of
// printing — the one moment it must be there.
import brandMarkSvg from '@/assets/brand/logo-mark.svg?raw'

const brandMark = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(brandMarkSvg)

const props = defineProps({
  sale: { type: Object, default: null },
  company: { type: String, default: 'Afghan China Shopping Center' },
  /**
   * Print the moment a sale lands. On at the POS (a finished sale should just
   * produce paper); off in the Sales ledger, where printing is a deliberate
   * reprint of an old bill.
   */
  autoPrint: { type: Boolean, default: false },
})
const { proxy } = getCurrentInstance()
const bc = ref(null)

/**
 * The layout chosen in the Receipt Designer. Defaults match the old hard-coded
 * receipt, so a shop that never opens the designer sees no change — and a
 * failed request never stops a sale from printing.
 */
const cfg = ref({
  paper: '80mm', font_size: 12,
  header_name: '', header_name_fa: '', tagline: '', address: '', phone: '',
  show_logo: true, show_cashier: true, show_customer: true, show_counter: true,
  show_unit_price: true, show_tax_line: true, show_discount_line: true,
  show_payment_lines: true, show_barcode: true, show_loyalty: true,
  show_branch: true, show_item_count: true, show_saving: true, auto_print: true,
  footer: '', footer_fa: '', note: '',
})

// The shop's own uploaded logo when there is one, otherwise the app's brand
// mark — so a bill always carries a mark, even on a fresh install.
const logoSrc = computed(() => {
  const custom = cfg.value.logo_url || companyLogo.value
  return custom ? assetUrl(custom) : brandMark
})

const lineCount = computed(() => (props.sale?.items || []).length)
const unitCount = computed(() => (props.sale?.items || []).reduce((s, i) => s + Number(i.qty || 0), 0))

/** What the compare-at prices say this basket would have cost elsewhere. */
const savedTotal = computed(() => {
  const items = props.sale?.items || []
  const fromCompare = items.reduce((s, i) => {
    const was = Number(i.compare_at_price || 0)
    const paid = Number(i.unit_price || 0)
    return s + (was > paid ? (was - paid) * Number(i.qty || 0) : 0)
  }, 0)
  return Math.round((fromCompare + Number(props.sale?.discount || 0)) * 100) / 100
})
const companyLogo = ref(null)
onMounted(async () => {
  try {
    const { data } = await api.get('/settings/receipt')
    if (data?.settings) cfg.value = { ...cfg.value, ...data.settings }
    if (data?.company?.logo) companyLogo.value = data.company.logo
  } catch (_) { /* keep the defaults — printing must never depend on this */ }
})

const fmt = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })
const num = (v) => Number(v || 0)
const fmtDate = (v) => v ? new Date(v).toLocaleString() : ''
const PAY = { cash: 'Cash', card: 'Card', mobile: 'Mobile', credit: 'Credit' }
const payLabel = (m) => proxy.$t(PAY[m] || m)

function renderBarcode () {
  nextTick(() => {
    if (cfg.value.show_barcode && bc.value && props.sale?.invoice_no) {
      try { JsBarcode(bc.value, String(props.sale.invoice_no), { format: 'CODE128', width: 1.4, height: 34, fontSize: 10, margin: 0, displayValue: true }) } catch (_) {}
    }
  })
}
watch(() => props.sale, renderBarcode, { immediate: true })

/**
 * The bill's own stylesheet, carried with the print job. The receipt is
 * printed as a self-contained raw-html document through the BUNDLED print-js
 * (no CDN — the register works offline), so what reaches the thermal printer
 * never depends on the app's screen CSS. Mirrors src/css/print.scss.
 */
const RECEIPT_CSS = `
  body { margin: 0; }
  .rcp { width: 76mm; margin: 0 auto; padding: 2mm 1mm;
    font-family: 'Courier New', monospace; color: #000; font-size: 11px; line-height: 1.35; }
  .rcp--58mm { width: 54mm; }
  .rcp--80mm { width: 76mm; }
  .rcp__head { text-align: center; margin-bottom: 4px; }
  .rcp__store { font-size: 14px; font-weight: 800; }
  .rcp__sub { font-size: 10px; }
  .rcp__meta { font-size: 10px; }
  .rcp__rule { border-top: 1px dashed #000; margin: 4px 0; }
  .rcp__items { width: 100%; border-collapse: collapse; font-size: 10.5px; }
  .rcp__items th { font-weight: 700; text-align: left; }
  .rcp__items .c { text-align: center; }
  .rcp__items .r, .rcp__items th.r { text-align: right; }
  .rcp__unit { font-size: 9px; color: #333; }
  .rcp__tot { display: flex; justify-content: space-between; font-size: 11px; }
  .rcp__tot--grand { font-weight: 800; font-size: 13px; }
  .rcp__barcode { display: block; margin: 6px auto 0; }
  .rcp__foot { text-align: center; margin-top: 6px; font-size: 10px; }
  .rcp__logo { display: block; margin: 0 auto 4px; width: 46px; height: auto; }
  .rcp__title { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 3px; }
  .rcp__metarow { display: flex; justify-content: space-between; gap: 8px; }
  .rcp__count { text-align: center; font-size: 10px; margin-top: 3px; }
  .rcp__saved { text-align: center; font-size: 11px; font-weight: 800; margin-top: 4px; }
  .rcp__loyal { text-align: center; margin-top: 5px; padding: 3px 0;
    font-size: 10px; font-weight: 700;
    border-top: 1px dashed #000; border-bottom: 1px dashed #000; }
  .rcp__note { text-align: center; margin-top: 4px; font-size: 9px; color: #333; white-space: pre-line; }
`

const root = ref(null)

function print () {
  renderBarcode()
  nextTick(async () => {
    const receipt = root.value?.querySelector('.rcp')
    if (!receipt) { window.print(); return }
    try {
      const { default: printJS } = await import('print-js')
      printJS({
        printable: receipt.outerHTML,
        type: 'raw-html',
        style: RECEIPT_CSS,
        documentTitle: props.sale?.invoice_no || 'receipt',
      })
    } catch (_) {
      // Bundled module failed to load — the browser dialog still prints
      // the receipt via print.scss's @media print rules.
      window.print()
    }
  })
}

/**
 * Fire the print as soon as a completed sale arrives, with nobody pressing
 * anything — that is what "it should print automatically" means in practice.
 *
 * A browser will still raise its own print dialog: no web page is allowed to
 * put ink on paper silently, and no amount of code changes that. To make it
 * truly one-touch, start Chrome once with:
 *
 *     chrome.exe --kiosk-printing --app=http://<pos-host>
 *
 * With that flag the dialog is skipped and the default printer is used, so a
 * sale ends with the receipt simply appearing. The behaviour is a setting
 * (`auto_print`) so a shop that prefers to press the button can turn it off.
 */
const printedFor = ref(null)
watch(() => props.sale, (sale) => {
  if (!sale?.id || !props.autoPrint || cfg.value.auto_print === false) return
  if (printedFor.value === sale.id) return   // never print the same bill twice
  printedFor.value = sale.id
  // Give the barcode and the logo a tick to paint before the snapshot.
  nextTick(() => setTimeout(print, 260))
})

defineExpose({ print })
</script>

<style>
/* Thermal receipt styles live in src/css/print.scss (loaded app-wide). */
</style>
