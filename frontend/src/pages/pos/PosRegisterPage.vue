<template>
  <div class="pos-root" :class="{ 'pos-full': isFullscreen }">
    <!-- ── LEFT: product browser ─────────────────────────────── -->
    <div class="pos-left">
      <div class="pos-topbar">
        <div class="row items-center no-wrap q-gutter-sm">
          <q-input
            ref="searchBox" v-model="search" dense rounded outlined
            bg-color="white" class="col pos-search"
            :placeholder="$t('SearchOrScan')"
            @keydown.enter="onEnter">
            <template #prepend><q-icon name="search" color="grey-5" /></template>
            <template #append>
              <q-icon name="qr_code_scanner" size="20px" class="cursor-pointer"
                :color="scanMode ? 'positive' : 'grey-5'" @click="toggleScan">
                <q-tooltip>{{ scanMode ? $t('ScanModeOn') : $t('ScanMode') }}</q-tooltip>
              </q-icon>
            </template>
          </q-input>

          <!-- Scanning works even when nothing is focused: the cashier can tap
               the cart, a dialog, anywhere — the next scan still lands. -->
          <product-scanner ref="scanner" mode="cart" :notify="false"
            @found="onScanFound" @missed="onScanMissed" />
          <q-btn round flat dense color="grey-6" class="pos-fs-btn"
            :icon="isFullscreen ? 'fullscreen_exit' : 'fullscreen'" @click="toggleFullscreen">
            <q-tooltip>{{ $t('Fullscreen') }}</q-tooltip>
          </q-btn>
          <q-btn v-if="canEditSettings" round flat dense color="grey-6" class="pos-fs-btn"
            icon="tune" @click="settingsOpen = true">
            <q-tooltip>{{ $t('RegisterSettings') }}</q-tooltip>
          </q-btn>
        </div>

        <div class="pos-cats row no-wrap q-mt-sm">
          <q-chip clickable dense class="pos-cat" :color="activeCat === null ? 'primary' : 'grey-2'"
            :text-color="activeCat === null ? 'white' : 'grey-8'" @click="activeCat = null">{{ $t('All') }}</q-chip>
          <q-chip v-for="c in categories" :key="c.id" clickable dense class="pos-cat"
            :color="activeCat === c.id ? 'primary' : 'grey-2'"
            :text-color="activeCat === c.id ? 'white' : 'grey-8'" @click="activeCat = c.id">{{ c.name }}</q-chip>
        </div>
      </div>

      <div class="pos-grid-wrap">
        <div v-if="loading" class="pos-empty"><q-spinner size="48px" color="primary" /></div>
        <div v-else-if="visible.length === 0" class="pos-empty">
          <q-icon name="inventory_2" size="56px" color="grey-3" />
          <div class="text-grey-5 q-mt-sm">{{ $t('NoItemsFound') }}</div>
        </div>
        <div v-else class="pos-grid">
          <div v-for="p in visible" :key="p.id" class="pos-card three_d" :class="{ 'pos-card--in': inCart(p.id) }" @click="addToCart(p)">
            <div class="pos-card__img">
              <img v-if="cfg.show_product_images && p.image_url" :src="imgUrl(p)" :alt="p.name" loading="lazy" />
              <div v-else class="pos-card__ph"><q-icon name="inventory_2" size="30px" color="grey-4" /></div>
              <div v-if="inCart(p.id)" class="pos-card__badge">{{ inCart(p.id) }}</div>
              <q-badge v-if="p.track_inventory && Number(p.stock_qty) <= 0" color="negative" class="pos-card__oos">{{ $t('OutOfStock') }}</q-badge>
            </div>
            <div class="pos-card__body">
              <div class="pos-card__name">{{ p.name }}</div>
              <div class="pos-card__code">{{ p.barcode || p.sku || '' }}</div>
              <div class="row items-center justify-between">
                <div class="pos-card__price">{{ money(p.sale_price) }}</div>
                <q-badge v-if="cfg.show_stock_badges && p.track_inventory" :color="Number(p.stock_qty) > 0 ? 'positive' : 'negative'">{{ Number(p.stock_qty) }}</q-badge>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── RIGHT: cart ──────────────────────────────────────── -->
    <div class="pos-right">
      <!-- held-bill tabs -->
      <div class="pos-tabs">
        <div class="pos-tabs__scroll row no-wrap items-center">
          <div v-for="o in orders" :key="o.id" class="pos-tab"
            :class="{ active: o.id === activeId, 'pos-tab--next': o.id === queueHeadId && o.cart.length && orders.filter(x => x.cart.length).length > 1 }"
            @click="activeId = o.id">
            <q-icon name="receipt_long" size="14px" />
            <span class="q-mx-xs">{{ o.label }}</span>
            <q-badge v-if="o.cart.length" rounded color="amber-8" text-color="white">{{ qtyOf(o) }}</q-badge>
            <q-icon name="close" size="15px" class="pos-tab__x q-ml-xs" @click.stop="closeOrder(o.id)" />
          </div>
        </div>
        <q-btn flat round dense icon="add" color="primary" size="sm" @click="newOrder"><q-tooltip>{{ $t('NewBill') }}</q-tooltip></q-btn>
      </div>

      <div class="pos-cart__head">
        <q-icon name="shopping_cart" size="20px" class="q-mr-xs" />
        <span class="text-weight-bold">{{ active ? active.label : $t('Cart') }}</span>
        <q-badge v-if="cart.length" color="amber-7" text-color="black" class="q-ml-sm">{{ totalQty }}</q-badge>
        <q-space />
        <q-btn v-if="cart.length" flat round dense icon="delete_sweep" color="red-4" size="sm" @click="clearCart"><q-tooltip>{{ $t('Clear') }}</q-tooltip></q-btn>
      </div>

      <!-- customer: pick one, or add a walk-in on the spot without leaving
           the register (a cashier cannot go to the Customers page mid-sale) -->
      <div class="pos-cust row no-wrap items-start q-gutter-xs">
        <q-select class="col" dense outlined options-dense v-model="customerId" :options="customerOpts"
          emit-value map-options clearable use-input @filter="filterCustomers"
          :label="$t('Customer')" bg-color="white">
          <template #prepend><q-icon name="person" color="primary" size="20px" /></template>
        </q-select>
        <button v-if="cfg.show_quick_customer" class="pos-addcust" @click="openNewCustomer">
          <q-icon name="person_add" size="19px" />
          <q-tooltip>{{ $t('AddCustomer') }}</q-tooltip>
        </button>
      </div>

      <!-- lines -->
      <q-scroll-area class="pos-lines">
        <div v-if="cart.length === 0" class="pos-empty pos-empty--sm">
          <q-icon name="shopping_cart" size="44px" color="grey-3" />
          <div class="text-grey-5 q-mt-sm">{{ $t('CartEmpty') }}</div>
          <div class="text-grey-4 text-caption">{{ $t('CartEmptyHint') }}</div>
        </div>
        <div v-for="(l, i) in cart" :key="l.product_id" class="pos-line">
          <div class="col">
            <div class="pos-line__name">{{ l.name }}</div>
            <div class="pos-line__price">{{ money(l.sale_price) }} <span v-if="l.tax_rate" class="text-grey-5">· {{ l.tax_rate }}%</span></div>
          </div>
          <div class="pos-qty">
            <q-btn round dense flat icon="remove" class="pos-qty__b" @click="dec(i)" />
            <!-- Tap the number for a keypad: "12 of these" without 12 taps. -->
            <span class="pos-qty__n">
              {{ l.qty }}
              <q-menu v-if="cfg.show_qty_keypad" anchor="center middle" self="center middle" class="pos-pad-menu">
                <div class="pos-pad">
                  <div class="pos-pad__head">{{ $t('SetQty') }} · {{ l.name }}</div>
                  <div class="pos-pad__val">{{ padValue || l.qty }}</div>
                  <div class="pos-pad__grid">
                    <button v-for="k in ['1','2','3','4','5','6','7','8','9','0']" :key="k"
                      class="pos-pad__k" @click="padPush(k)">{{ k }}</button>
                    <button class="pos-pad__k pos-pad__k--alt" @click="padValue = ''">C</button>
                    <button class="pos-pad__k pos-pad__k--ok" v-close-popup @click="padApply(i)">
                      <q-icon name="check" size="20px" />
                    </button>
                  </div>
                </div>
              </q-menu>
            </span>
            <q-btn round dense flat icon="add" color="primary" class="pos-qty__b" @click="inc(i)" />
          </div>
          <div class="pos-line__total">{{ money(lineTotal(l)) }}</div>
          <q-btn round dense flat size="sm" icon="close" color="red-4" @click="removeLine(i)" />
        </div>
      </q-scroll-area>

      <!-- totals + checkout -->
      <div class="pos-foot">
        <div class="row justify-between pos-foot__row"><span>{{ $t('Subtotal') }}</span><b>{{ money(subtotal) }}</b></div>
        <div class="row items-center pos-foot__row">
          <span>{{ $t('Discount') }}</span><q-space />
          <q-input dense borderless input-class="text-right" style="width:110px" type="number" min="0"
            v-model.number="billDiscount" :disable="!cart.length" suffix="AFN" />
        </div>
        <div class="row justify-between pos-foot__row"><span>{{ $t('Tax') }}</span><span>{{ money(taxTotal) }}</span></div>
        <q-separator class="q-my-sm" />
        <div class="row justify-between pos-foot__grand"><span>{{ $t('Total') }}</span><span>{{ money(grandTotal) }}</span></div>

        <!-- The fast lane: most sales are cash for the exact amount, so that
             is one tap straight to done. Everything else goes through the
             payment sheet below it. -->
        <!-- One tap finishes a cash sale. Card / split / custom amounts live
             behind the small tender button next to it (also F2) — removing
             that outright would leave no way to take a card. -->
        <!-- Queue lock: this bill waits its turn behind the first one. -->
        <div v-if="queueBlocked" class="pos-queue-lock" role="button" tabindex="0" @click="goToQueueHead" @keyup.enter="goToQueueHead">
          <q-icon name="lock_clock" size="16px" />
          <span>{{ $t('FinishFirstOrder') }}</span>
          <q-icon name="arrow_forward" size="14px" />
        </div>

        <div class="pos-pay-row">
          <button class="pos-cash" :disabled="!cart.length || saving || queueBlocked" @click="cashExact">
            <q-icon :name="saving ? 'hourglass_top' : 'payments'" size="20px" />
            <span class="pos-cash__t">
              <b>{{ money(grandTotal) }}</b>
              <small>{{ $t('CashExact') }}</small>
            </span>
          </button>
          <!-- Kept as a visible option, not just an icon: this is the route to
               a card, a split tender, and the Change readout when the customer
               pays more than the total. -->
          <button class="pos-tender" :disabled="!cart.length || queueBlocked" @click="openPay">
            <q-icon name="credit_card" size="17px" />
            <span>{{ $t('Charge') }}</span>
            <q-tooltip>{{ $t('OtherPayment') }} · F2</q-tooltip>
          </button>
        </div>
      </div>
    </div>

    <!-- ── register settings (admin / VIP) ─────────────────── -->
    <q-dialog v-model="settingsOpen">
      <q-card class="pos-set-card">
        <n-header icon="tune">{{ $t('RegisterSettings') }}</n-header>
        <q-separator />
        <q-card-section class="q-pb-none">
          <div class="text-caption text-grey-6">{{ $t('RegisterSettingsHint') }}</div>
        </q-card-section>
        <q-card-section class="q-gutter-none">
          <div v-for="t in SETTING_TOGGLES" :key="t.key" class="pos-set__row">
            <div class="col">
              <div class="pos-set__name">{{ $t(t.label) }}</div>
              <div class="pos-set__hint">{{ $t(t.hint) }}</div>
            </div>
            <q-toggle v-model="draft[t.key]" color="primary" dense />
          </div>

          <!-- the timer's length only matters when the timer is on -->
          <div v-if="draft.auto_next" class="pos-set__row">
            <div class="col">
              <div class="pos-set__name">{{ $t('AutoNextSeconds') }}</div>
              <div class="pos-set__hint">{{ draft.auto_next_seconds }} {{ $t('Seconds') }}</div>
            </div>
            <q-slider v-model="draft.auto_next_seconds" :min="3" :max="30" :step="1"
              label color="primary" style="max-width:170px" />
          </div>

          <div class="pos-set__row">
            <div class="col">
              <div class="pos-set__name">{{ $t('NoteDenominations') }}</div>
              <div class="pos-set__hint">{{ $t('NoteDenominationsHint') }}</div>
            </div>
            <q-input dense outlined v-model="draft.note_denominations" style="max-width:190px"
              :rules="[v => /^\s*\d+\s*(,\s*\d+\s*)*$/.test(v || '') || $t('NumbersOnly')]" />
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('ResetToDefault')" @click="resetSettings" />
          <q-space />
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="primary" icon="check" :label="$t('Save')"
            :loading="savingSettings" @click="saveSettings" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- ── quick-add customer ──────────────────────────────── -->
    <q-dialog v-model="custOpen">
      <q-card class="pos-cust-card">
        <n-header icon="person_add">{{ $t('AddCustomer') }}</n-header>
        <q-separator />
        <q-form @submit="saveCustomer">
          <q-card-section class="q-gutter-sm">
            <q-input outlined dense autofocus v-model="newCust.name" :label="$t('Name')"
              :rules="[v => !!(v && v.trim()) || $t('FieldIsRequired')]">
              <template #prepend><q-icon name="person" color="primary" /></template>
            </q-input>
            <q-input outlined dense v-model="newCust.phone" :label="$t('Phone')">
              <template #prepend><q-icon name="phone" color="primary" /></template>
            </q-input>
          </q-card-section>
          <q-separator />
          <q-card-actions align="right" class="q-pa-md">
            <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
            <q-btn unelevated no-caps color="primary" icon="check" type="submit"
              :label="$t('Save')" :loading="savingCust" />
          </q-card-actions>
        </q-form>
      </q-card>
    </q-dialog>

    <!-- ── PAYMENT dialog ───────────────────────────────────── -->
    <q-dialog v-model="payOpen">
      <q-card class="pos-pay-card">
        <n-header icon="payments">{{ $t('Payment') }}</n-header>
        <q-separator />
        <q-card-section class="q-pb-sm">
          <div class="pos-pay-due"><span>{{ $t('AmountDue') }}</span><b>{{ money(grandTotal) }}</b></div>

          <div class="pos-meth">
            <button v-for="m in methods" :key="m.v" class="pos-meth__b"
              :class="{ 'pos-meth__b--on': tender.method === m.v }" @click="tender.method = m.v">
              <q-icon :name="m.icon" size="22px" />
              <span>{{ $t(m.label) }}</span>
            </button>
          </div>

          <!-- Tendered: big enough to read across a counter, and typeable. -->
          <div class="pos-tend">
            <span class="pos-tend__l">{{ $t('Tendered') }}</span>
            <input class="pos-tend__i" type="number" min="0" step="any" ref="tenderInput"
              v-model.number="tender.amount" @keydown.enter="complete" />
            <span class="pos-tend__c">AFN</span>
          </div>

          <div class="pos-notes__l">{{ $t('TapNotes') }}</div>
          <div class="pos-notes">
            <button v-for="n in NOTES" :key="n" class="pos-note" @click="addNote(n)">+{{ n }}</button>
          </div>
          <div class="row q-gutter-xs q-mt-xs justify-center">
            <q-btn dense flat no-caps color="primary" icon="done" :label="$t('Exact')"
              @click="tender.amount = round2(grandTotal)" />
            <q-btn v-for="q in quickCash" :key="q" dense flat no-caps color="primary" :label="money(q)"
              @click="tender.amount = q" />
            <q-btn dense flat no-caps color="grey-7" icon="backspace" :label="$t('Clear')"
              @click="tender.amount = 0" />
          </div>

          <div class="pos-change" :class="{ 'pos-change--ok': change >= 0 }">
            <span>{{ change >= 0 ? $t('Change') : $t('Short') }}</span>
            <b>{{ money(Math.abs(change)) }}</b>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions class="q-pa-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-space />
          <q-btn unelevated no-caps color="primary" icon="check_circle" size="lg" class="pos-done"
            :label="$t('CompleteSale')" :loading="saving" :disable="change < 0" @click="complete" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- A finished sale should simply produce paper: no button, no waiting.
         (The Sales ledger keeps manual reprints, which is why auto-print is
         opt-in per page rather than baked into the component.) -->
    <receipt-print ref="receiptRef" :sale="receiptSale" :company="companyName" auto-print />
    <print-options ref="printOptionsRef" />

    <!-- ── RECEIPT dialog ───────────────────────────────────── -->
    <q-dialog v-model="receiptOpen">
      <q-card class="pos-receipt">
        <div class="pos-receipt__top">
          <q-icon name="check_circle" size="46px" color="positive" />
          <div class="text-h6 q-mt-xs">{{ $t('SaleComplete') }}</div>
          <div class="text-grey-6">{{ lastSale?.invoice_no }}</div>
          <!-- The one number the cashier needs, in the size they need it. -->
          <div class="pos-give" :class="{ 'pos-give--none': !givingBack }">
            <span>{{ $t('Change') }}</span>
            <b>{{ money(givingBack) }}</b>
            <small v-if="!givingBack && !receiptReceived">{{ $t('OneTapDone') }}</small>
          </div>

          <!-- The one-tap cash button records the exact total, so a customer
               who hands over a 500 note leaves nothing here to work the change
               out from. Typing what they actually gave does it on the spot.
               This is a counter aid, not a correction: the sale is already
               recorded and this does not change it. -->
          <div v-if="cfg.show_receipt_change" class="pos-rgot">
            <span class="pos-rgot__l">{{ $t('CashReceived') }}</span>
            <input class="pos-rgot__i" type="number" min="0" step="any" inputmode="decimal"
              ref="receiptRecvInput" v-model.number="receiptReceived" @focus="holdReceipt"
              :placeholder="String(round2(Number(lastSale?.paid) || 0))" />
            <button v-if="receiptReceived !== null && receiptReceived !== ''" class="pos-rgot__x"
              @click="receiptReceived = null"><q-icon name="close" size="14px" /></button>
          </div>
          <!-- One-touch tendering: the notes that settle this bill on their
               own, smallest first. Tapping one SETS the amount rather than
               adding to it, because a customer hands over a single note. -->
          <div v-if="cfg.show_receipt_change && receiptNotes.length" class="pos-rgot__notes">
            <button v-for="n in receiptNotes" :key="n" class="pos-rgot__note"
              :class="{ 'pos-rgot__note--on': receiptRecvNum === n }"
              @click="setReceiptReceived(n)">{{ n }}</button>
            <button class="pos-rgot__note pos-rgot__note--exact" @click="setReceiptReceived(receiptTotal)">
              {{ $t('Exact') }}
            </button>
          </div>
          <div v-if="cfg.show_receipt_change && receiptShort > 0" class="pos-rgot__short">
            {{ $t('Short') }} <b>{{ money(receiptShort) }}</b>
          </div>
        </div>
        <q-separator />
        <q-card-section class="pos-receipt__body">
          <div v-for="it in (lastSale?.items || [])" :key="it.id" class="row justify-between pos-receipt__line">
            <span>{{ qty(it.qty) }} × {{ it.name }}</span><span>{{ money(it.line_total) }}</span>
          </div>
          <q-separator class="q-my-sm" />
          <div class="row justify-between"><span>{{ $t('Total') }}</span><b>{{ money(receiptSale?.total) }}</b></div>
          <div class="row justify-between text-grey-7"><span>{{ $t('Paid') }}</span><span>{{ money(receiptSale?.paid) }}</span></div>
          <div class="row justify-between text-positive"><span>{{ $t('Change') }}</span><span>{{ money(receiptSale?.change_due) }}</span></div>
        </q-card-section>
        <q-separator />
        <q-card-actions class="q-pa-md">
          <q-btn flat no-caps color="grey-7" icon="print" :label="$t('Print')" @click="printReceipt" />
          <q-space />
          <q-btn unelevated no-caps color="primary" icon="add_shopping_cart" size="lg"
            :label="$t('NextSale')" v-close-popup @click="afterReceipt">
            <!-- Nobody has to tap this: it clears itself so the next customer
                 can be scanned straight away. -->
            <q-badge v-if="autoIn > 0" floating color="white" text-color="primary">{{ autoIn }}</q-badge>
          </q-btn>
        </q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>

<script setup>
import { ref, computed, reactive, watch, onMounted, onUnmounted, getCurrentInstance } from 'vue'
import { useQuasar } from 'quasar'
import { api, assetUrl } from '@/boot/axios'
import { useUiStore } from '@/stores/ui'
import ReceiptPrint from '@/components/ReceiptPrint.vue'
import PrintOptions from '@/components/PrintOptions.vue'

const $q = useQuasar()
const { proxy } = getCurrentInstance()
const t = (k) => (proxy?.$t ? proxy.$t(k) : k)

const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' AFN'
const round2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100
// The API returns decimals ("7.000"); a receipt should read "7 ×", not "7.000 ×".
const qty = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 3 })

// ── data ───────────────────────────────────────────────
const products = ref([])
const categories = ref([])
const customers = ref([])
const loading = ref(false)
const search = ref('')
const activeCat = ref(null)
const scanMode = ref(false)
const isFullscreen = ref(false)
const searchBox = ref(null)

async function load () {
  loading.value = true
  try {
    const [p, c, cu] = await Promise.all([
      api.get('/pos/catalog'),
      api.get('/product-categories'),
      api.get('/customers'),
    ])
    products.value = p.data || []
    categories.value = c.data || []
    customers.value = cu.data || []
  } catch (_) { /* toast below */ } finally { loading.value = false }
}
onMounted(() => { load(); loadSettings() })

const visible = computed(() => {
  const s = search.value.trim().toLowerCase()
  return products.value.filter(p => {
    if (activeCat.value && p.category_id !== activeCat.value) return false
    if (!s) return true
    return (p.name || '').toLowerCase().includes(s) ||
      (p.barcode || '').toLowerCase().includes(s) ||
      (p.sku || '').toLowerCase().includes(s)
  })
})

function imgUrl (p) { return assetUrl(p.image_url) }

// ── multi-tab held orders ───────────────────────────────
let seq = 1
const orders = ref([{ id: seq, label: t('Bill') + ' 1', cart: [], billDiscount: 0 }])
const activeId = ref(1)
const active = computed(() => orders.value.find(o => o.id === activeId.value))
const cart = computed(() => active.value ? active.value.cart : [])

function newOrder () { seq += 1; orders.value.push({ id: seq, label: t('Bill') + ' ' + (orders.value.length + 1), cart: [], billDiscount: 0 }); activeId.value = seq }
function closeOrder (id) {
  const o = orders.value.find(x => x.id === id)
  if (o && o.cart.length) {
    $q.dialog({ title: t('CloseBill'), message: t('CloseBillConfirm'), cancel: true }).onOk(() => doClose(id))
  } else { doClose(id) }
}
function doClose (id) {
  const idx = orders.value.findIndex(x => x.id === id)
  orders.value.splice(idx, 1)
  if (orders.value.length === 0) { seq += 1; orders.value.push({ id: seq, label: t('Bill') + ' 1', cart: [], billDiscount: 0 }) }
  if (activeId.value === id) activeId.value = orders.value[0].id
}
function qtyOf (o) { return o.cart.reduce((s, l) => s + Number(l.qty), 0) }

const billDiscount = computed({
  get: () => active.value ? Number(active.value.billDiscount || 0) : 0,
  set: (v) => { if (active.value) active.value.billDiscount = Number(v) || 0 },
})

// ── first-in-queue checkout ─────────────────────────────
// Stacked bills print in order or receipts get swapped between customers:
// the OLDEST bill with items is the queue head, and only it may check out.
// Later bills stay open for scanning but their pay buttons are locked until
// the head is finished (or closed).
const queueHeadId = computed(() => {
  const withItems = orders.value.filter(o => o.cart.length)
  return withItems.length ? withItems[0].id : (active.value?.id ?? null)
})
const queueBlocked = computed(() =>
  !!active.value && cart.value.length > 0 && queueHeadId.value !== active.value.id
)
function goToQueueHead () { if (queueHeadId.value != null) activeId.value = queueHeadId.value }

// ── cart ops ────────────────────────────────────────────
function inCart (id) { const l = cart.value.find(x => x.product_id === id); return l ? l.qty : 0 }
function addToCart (p) {
  if (p.track_inventory && Number(p.stock_qty) <= 0 && !cfg.value.allow_negative_stock) {
    notify(t('OutOfStock'), 'negative'); return
  }
  // Tapping a card steals focus from the search box; give it straight back so
  // the barcode scanner never stops working mid-basket.
  setTimeout(() => searchBox.value?.focus?.(), 0)
  const l = cart.value.find(x => x.product_id === p.id)
  if (l) {
    if (p.track_inventory && l.qty + 1 > Number(p.stock_qty) && !cfg.value.allow_negative_stock) {
      notify(t('NotEnoughStock'), 'warning'); return
    }
    l.qty += 1
  } else {
    cart.value.push({ product_id: p.id, name: p.name, sale_price: Number(p.sale_price), tax_rate: Number(p.tax_rate || 0), qty: 1, stock_qty: Number(p.stock_qty), track: p.track_inventory })
  }
}
function inc (i) { const l = cart.value[i]; if (l.track && l.qty + 1 > l.stock_qty) { notify(t('NotEnoughStock'), 'warning'); return } l.qty += 1 }
function dec (i) { const l = cart.value[i]; l.qty -= 1; if (l.qty <= 0) cart.value.splice(i, 1) }

// ── qty keypad: typing "12" beats tapping + twelve times ──
const padValue = ref('')
function padPush (k) { if (padValue.value.length < 4) padValue.value += k }
function padApply (i) {
  const l = cart.value[i]
  const n = Math.max(0, parseInt(padValue.value || '0', 10))
  padValue.value = ''
  if (!l) return
  if (n <= 0) { cart.value.splice(i, 1); return }
  if (l.track && n > l.stock_qty) { notify(t('NotEnoughStock'), 'warning'); l.qty = l.stock_qty; return }
  l.qty = n
}
function removeLine (i) { cart.value.splice(i, 1) }
function clearCart () {
  if (!active.value) return
  if (cfg.value.confirm_clear_bill && cart.value.length) {
    $q.dialog({ title: t('Clear'), message: t('ClearBillConfirm'), cancel: true })
      .onOk(() => { active.value.cart = [] })
    return
  }
  active.value.cart = []
}

const lineTotal = (l) => round2(l.sale_price * l.qty)
const totalQty = computed(() => cart.value.reduce((s, l) => s + Number(l.qty), 0))
const subtotal = computed(() => round2(cart.value.reduce((s, l) => s + l.sale_price * l.qty, 0)))
const taxTotal = computed(() => round2(cart.value.reduce((s, l) => s + (l.sale_price * l.qty) * (l.tax_rate / 100), 0)))
const grandTotal = computed(() => round2(Math.max(0, subtotal.value - billDiscount.value + taxTotal.value)))

// ── search / scan ───────────────────────────────────────
function toggleScan () { scanMode.value = !scanMode.value; searchBox.value?.focus?.() }
const scanner = ref(null)

async function onEnter () {
  const code = search.value.trim()
  if (!code) return
  // Exact hit already on screen → straight to the cart, no round trip.
  const local = products.value.find(p => p.barcode === code || p.sku === code)
  if (local) { addToCart(local); search.value = ''; return }
  // Otherwise ask the shared lookup, which knows every code a product carries
  // (second barcode, internal code, SKU…) and handles misses and duplicates.
  search.value = ''
  scanner.value?.lookup(code)
}

/**
 * A scan resolved to one product. Add it, say so, and hand focus back — the
 * next scan must not need a click. Quantity is bumped rather than a second
 * line added, which addToCart already does.
 */
function onScanFound (p) {
  if (!products.value.find(x => x.id === p.id)) products.value.push(p)
  const before = inCart(p.id)
  addToCart(p)
  const after = inCart(p.id)
  if (after > before) {
    notify(`${p.name} — ${t('AddedToCart')} ×${after}`, 'positive')
  }
  search.value = ''
  refocusScan()
}

function onScanMissed () { refocusScan() }

/** Continuous scanning: the box takes focus back after every event. */
function refocusScan () {
  setTimeout(() => searchBox.value?.focus?.(), 60)
}

// ── customer select ─────────────────────────────────────
const customerId = ref(null)
const customerFilter = ref('')
const customerOpts = computed(() => customers.value
  .filter(c => !customerFilter.value || (c.name + ' ' + (c.phone || '')).toLowerCase().includes(customerFilter.value))
  .map(c => ({ label: c.phone ? `${c.name} · ${c.phone}` : c.name, value: c.id })))
function filterCustomers (val, update) { update(() => { customerFilter.value = (val || '').toLowerCase() }) }

// Quick-add: a walk-in who wants their purchases on record should not cost the
// cashier a trip to the Customers page. Saved, then selected on this bill.
const custOpen = ref(false)
const savingCust = ref(false)
const newCust = reactive({ name: '', phone: '' })

function openNewCustomer () {
  newCust.name = ''
  newCust.phone = ''
  custOpen.value = true
}

async function saveCustomer () {
  if (!newCust.name.trim()) return
  savingCust.value = true
  try {
    const { data } = await api.post('/customers', { name: newCust.name.trim(), phone: newCust.phone || null })
    customers.value = [data, ...customers.value]
    customerId.value = data.id
    custOpen.value = false
    notify(t('Saved'), 'positive')
  } catch (e) {
    notify(e?.response?.data?.message || t('SaveFailed'), 'negative')
  } finally { savingCust.value = false }
}

// ── register settings ───────────────────────────────────
// Read by every cashier so the register behaves the same at every seat;
// editable only by an administrator or the VIP seat.
const POS_DEFAULTS = {
  auto_next: true, auto_next_seconds: 8,
  show_receipt_change: true, show_quick_customer: true, show_qty_keypad: true,
  confirm_clear_bill: true, default_scan_mode: false,
  show_product_images: true, show_stock_badges: true, allow_negative_stock: false,
  note_denominations: '10,20,50,100,500,1000',
}
const cfg = ref({ ...POS_DEFAULTS })
const canEditSettings = ref(false)
const settingsOpen = ref(false)
const savingSettings = ref(false)
const draft = reactive({ ...POS_DEFAULTS })

const SETTING_TOGGLES = [
  { key: 'auto_next', label: 'AutoNextSale', hint: 'AutoNextSaleHint' },
  { key: 'show_receipt_change', label: 'ShowReceiptChange', hint: 'ShowReceiptChangeHint' },
  { key: 'show_quick_customer', label: 'ShowQuickCustomer', hint: 'ShowQuickCustomerHint' },
  { key: 'show_qty_keypad', label: 'ShowQtyKeypad', hint: 'ShowQtyKeypadHint' },
  { key: 'confirm_clear_bill', label: 'ConfirmClearBill', hint: 'ConfirmClearBillHint' },
  { key: 'default_scan_mode', label: 'DefaultScanMode', hint: 'DefaultScanModeHint' },
  { key: 'show_product_images', label: 'ShowProductImages', hint: 'ShowProductImagesHint' },
  { key: 'show_stock_badges', label: 'ShowStockBadges', hint: 'ShowStockBadgesHint' },
  { key: 'allow_negative_stock', label: 'AllowNegativeStock', hint: 'AllowNegativeStockHint' },
]

async function loadSettings () {
  try {
    const { data } = await api.get('/settings/pos')
    if (data?.settings) cfg.value = { ...POS_DEFAULTS, ...data.settings }
    canEditSettings.value = !!data?.can_edit
    scanMode.value = !!cfg.value.default_scan_mode
  } catch (_) { /* the register must open even if this fails */ }
}
// Open the dialog on the values in force, not on whatever was last typed.
watch(settingsOpen, (open) => { if (open) Object.assign(draft, cfg.value) })

async function saveSettings () {
  savingSettings.value = true
  try {
    const { data } = await api.put('/settings/pos', { ...draft })
    cfg.value = { ...POS_DEFAULTS, ...(data.settings || {}) }
    settingsOpen.value = false
    notify(t('Saved'), 'positive')
  } catch (e) {
    notify(e?.response?.data?.message || t('SaveFailed'), 'negative')
  } finally { savingSettings.value = false }
}
function resetSettings () { Object.assign(draft, POS_DEFAULTS) }

/** The banknotes the tender pads offer, from the settings. */
const noteList = computed(() => String(cfg.value.note_denominations || '')
  .split(',').map(n => parseInt(String(n).trim(), 10))
  .filter(n => Number.isFinite(n) && n > 0)
  .sort((a, b) => a - b).slice(0, 8))

/**
 * The notes to offer for one-touch tendering, the way every till does it: only
 * the denominations that cover the bill on their own, smallest first.
 *
 * This is the "greater or equal to amount due" rule — a 47 bill offers 50, 100,
 * 500, 1000, and never 10 or 20, because a single 10 cannot pay it. One tap
 * then settles the sale and the change appears. (The other standard mode, where
 * every note is shown and taps accumulate, is what the footer pad does — two
 * 20s and a 10 for that same 47.)
 *
 * When the bill is larger than the biggest note nothing covers it alone, so the
 * list falls back to whole multiples of that note — 2000, 3000 for a 1,250 bill
 * — which is how the cash actually arrives.
 */
function tenderSuggestions (total, notes) {
  const t = round2(total)
  if (!(t > 0) || !notes.length) return []

  const covering = notes.filter(n => n >= t)
  if (covering.length >= 4) return covering.slice(0, 4)

  const out = [...covering]
  const biggest = notes[notes.length - 1]
  for (let m = Math.ceil(t / biggest) * biggest; out.length < 4; m += biggest) {
    if (!out.includes(m)) out.push(m)
  }

  return out.sort((a, b) => a - b).slice(0, 4)
}

// ── payment ─────────────────────────────────────────────
const payOpen = ref(false)
const saving = ref(false)
const tenderInput = ref(null)
const tender = reactive({ method: 'cash', amount: 0 })
const methods = [
  { v: 'cash', label: 'Cash', icon: 'payments' },
  { v: 'card', label: 'Card', icon: 'credit_card' },
  { v: 'mobile', label: 'Mobile', icon: 'smartphone' },
]
// Real notes that cover the bill, not round multiples of 100. The old version
// offered 100/500/1000 for a 47 bill and skipped the 50 — the very note the
// customer is most likely to hand over.
const quickCash = computed(() => tenderSuggestions(grandTotal.value, noteList.value))
const change = computed(() => round2((Number(tender.amount) || 0) - grandTotal.value))

// The notes that circulate in AFN come from the settings. Tapping one ADDS it,
// because that is what happens at the counter — two 500s land as 1000, not 500.
const NOTES = computed(() => noteList.value)
function addNote (n) { tender.amount = round2((Number(tender.amount) || 0) + n) }

function openPay () {
  if (!cart.value.length) return
  if (queueBlocked.value) { notify(t('FinishFirstOrder'), 'warning'); goToQueueHead(); return }
  tender.method = 'cash'
  // Start from zero so the note tiles read as "what did they hand you".
  tender.amount = 0
  payOpen.value = true
}

/**
 * The fast lane: cash for the exact amount, no dialog. What the customer
 * actually handed over is worked out afterwards, on the receipt — one place
 * for that, rather than a box here and another there.
 */
function cashExact () {
  if (!cart.value.length || saving.value) return
  if (queueBlocked.value) { notify(t('FinishFirstOrder'), 'warning'); goToQueueHead(); return }
  tender.method = 'cash'
  tender.amount = round2(grandTotal.value)
  complete()
}

const receiptOpen = ref(false)
const lastSale = ref(null)

// ── change worked out on the receipt ────────────────────
// What the customer actually handed over, typed after the sale is rung. Empty
// means "whatever the sale recorded", which is the normal case.
const receiptReceived = ref(null)
const receiptRecvInput = ref(null)

const receiptTotal = computed(() => round2(Number(lastSale.value?.total) || 0))
const receiptNotes = computed(() => tenderSuggestions(receiptTotal.value, noteList.value))
function setReceiptReceived (n) {
  receiptReceived.value = round2(n)
  holdReceipt()
}

const receiptRecvNum = computed(() => {
  const v = receiptReceived.value
  return v === null || v === '' ? null : Number(v)
})

/** The note to hand back: from what was typed if anything was, else the sale. */
const givingBack = computed(() => {
  if (receiptRecvNum.value === null) return round2(Number(lastSale.value?.change_due) || 0)
  return Math.max(0, round2(receiptRecvNum.value - receiptTotal.value))
})

/** Typed less than the bill — worth saying out loud before they walk off. */
const receiptShort = computed(() => {
  if (receiptRecvNum.value === null) return 0
  return Math.max(0, round2(receiptTotal.value - receiptRecvNum.value))
})

/**
 * The sale as the receipt should read it. Once a cashier types what the
 * customer handed over, the Paid and Change lines have to follow — otherwise
 * the slip shows a big "Change 953" above a "Change 0", and the paper
 * contradicts the screen.
 *
 * The recorded sale is deliberately left alone: revenue is the bill either way,
 * and a completed sale is not rewritten from the receipt dialog. A short
 * entry is ignored here too — no slip should ever claim less was paid than the
 * bill came to.
 */
const receiptSale = computed(() => {
  if (!lastSale.value) return null
  if (receiptRecvNum.value === null || receiptShort.value > 0) return lastSale.value
  return { ...lastSale.value, paid: receiptRecvNum.value, change_due: givingBack.value }
})

async function complete () {
  if (change.value < 0) return
  saving.value = true
  try {
    const payload = {
      items: cart.value.map(l => ({ product_id: l.product_id, qty: l.qty })),
      bill_discount: billDiscount.value,
      customer_id: customerId.value || null,
      payments: [{ method: tender.method, amount: round2(tender.amount) }],
    }
    const { data } = await api.post('/pos/checkout', payload)
    lastSale.value = data
    receiptReceived.value = null
    payOpen.value = false
    receiptOpen.value = true
    startAutoNext()
    // refresh stock for sold items
    load()
  } catch (e) {
    notify(e?.response?.data?.message || t('CheckoutFailed'), 'negative')
  } finally { saving.value = false }
}

/**
 * After a sale the register clears itself. The cashier can scan the next
 * customer's first item immediately instead of dismissing a dialog — but the
 * receipt stays up long enough to hand over change and hit Print.
 */
const autoIn = ref(0)
let autoTimer = null
function startAutoNext (seconds = null) {
  // Off by setting: some counters would rather dismiss the receipt themselves.
  if (!cfg.value.auto_next) { autoIn.value = 0; return }
  seconds = seconds ?? (Number(cfg.value.auto_next_seconds) || 8)
  clearInterval(autoTimer)
  autoIn.value = seconds
  autoTimer = setInterval(() => {
    autoIn.value -= 1
    if (autoIn.value <= 0) {
      clearInterval(autoTimer)
      if (receiptOpen.value) { receiptOpen.value = false; afterReceipt() }
    }
  }, 1000)
}

/**
 * Working out change takes longer than the auto-close timer, and having the
 * receipt disappear mid-figure is worse than never having the box. Touching it
 * cancels the countdown; the cashier dismisses the receipt themselves.
 */
function holdReceipt () {
  clearInterval(autoTimer)
  autoIn.value = 0
}

function afterReceipt () {
  clearInterval(autoTimer)
  autoIn.value = 0
  // close current bill, reset
  if (active.value) { active.value.cart = []; active.value.billDiscount = 0 }
  receiptReceived.value = null
  customerId.value = null
  searchBox.value?.focus?.()
}
onUnmounted(() => clearInterval(autoTimer))
const receiptRef = ref(null)
const companyName = ref('Afghan China Shopping Center')
api.get('/user').then(({ data }) => { companyName.value = data?.company?.name_en || companyName.value }).catch(() => {})
const printOptionsRef = ref(null)
function printReceipt () {
  printOptionsRef.value?.show(async (options) => {
    for (let i = 0; i < options.copies; i++) {
      if (i > 0) await new Promise(r => setTimeout(r, 500))
      receiptRef.value?.print()
    }
  })
}

function notify (message, color = 'primary') { $q.notify({ message, color, position: 'top', timeout: 2200 }) }

// ── fullscreen: the register takes the WHOLE frame ──────
// Kiosk mode hides the app header + sidebar so the POS fills the screen,
// and also asks the browser for true fullscreen where available.
const ui = useUiStore()
function toggleFullscreen () {
  isFullscreen.value = !isFullscreen.value
  ui.posKiosk = isFullscreen.value
  try { $q.fullscreen.toggle() } catch (_) { /* browser may refuse without gesture */ }
}
onUnmounted(() => { ui.posKiosk = false })
function onKey (e) {
  // F1 finishes a cash sale outright — the shortcut for the busiest case.
  if (e.key === 'F1') { e.preventDefault(); if (receiptOpen.value) { receiptOpen.value = false; afterReceipt() } else cashExact() }
  else if (e.key === 'F2') { e.preventDefault(); openPay() }
  else if (e.key === 'F4') { e.preventDefault(); newOrder() }
  else if (e.key === 'Escape' && receiptOpen.value) { receiptOpen.value = false; afterReceipt() }
}
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<style scoped>
.pos-root { display: flex; height: calc(100vh - 48px); background: #EEF2F6; overflow: hidden; }
.pos-full { height: 100vh; }
.pos-left { flex: 1 1 auto; display: flex; flex-direction: column; min-width: 0; padding: 12px; }
.pos-right { width: 380px; flex-shrink: 0; background: #fff; border-inline-start: 1px solid #E2E8F0; display: flex; flex-direction: column; }

.pos-topbar { flex-shrink: 0; }
.pos-search :deep(.q-field__control) { border-radius: 22px; }
.pos-cats { overflow-x: auto; gap: 8px; padding-bottom: 4px; }
.pos-cat { flex-shrink: 0; font-weight: 600; }

.pos-grid-wrap { flex: 1; overflow-y: auto; margin-top: 10px; }
.pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
.pos-card { background: #fff; border: 1.5px solid #E7ECF3; border-radius: 14px; overflow: hidden; cursor: pointer; transition: all .18s ease; }
.pos-card:hover { transform: translateY(-3px); border-color: var(--q-primary); box-shadow: 0 12px 22px -16px rgba(18,58,102,.55); }
.pos-card--in { border-color: var(--q-primary); box-shadow: 0 0 0 2px color-mix(in srgb, var(--q-primary) 30%, #fff) inset; }
.pos-card__img { position: relative; aspect-ratio: 16/10; background: #F1F5F9; display: flex; align-items: center; justify-content: center; }
.pos-card__img img { width: 100%; height: 100%; object-fit: cover; }
.pos-card__badge { position: absolute; top: 6px; inset-inline-end: 6px; min-width: 22px; height: 22px; border-radius: 11px; background: var(--q-primary); color: #fff; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; padding: 0 6px; }
.pos-card__oos { position: absolute; bottom: 6px; inset-inline-start: 6px; }
.pos-card__body { padding: 8px 10px; }
.pos-card__name { font-size: 13px; font-weight: 700; color: #1E293B; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 32px; }
.pos-card__code { font-size: 10.5px; color: #94A3B8; margin: 2px 0 4px; }
.pos-card__price { font-size: 14px; font-weight: 800; color: var(--q-primary); }

.pos-empty { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.pos-empty--sm { height: 220px; }

.pos-tabs { display: flex; align-items: center; background: #123A66; padding: 4px 6px; gap: 4px; }
.pos-tabs__scroll { overflow-x: auto; flex: 1; gap: 4px; }
.pos-tab { display: flex; align-items: center; color: #CBD5E1; background: rgba(255,255,255,.08); border-radius: 8px; padding: 5px 10px; font-size: 12px; cursor: pointer; white-space: nowrap; }
.pos-tab.active { background: #fff; color: #123A66; font-weight: 700; }
.pos-tab__x { opacity: .7; }
.pos-tab__x:hover { opacity: 1; }
.pos-tab--next { box-shadow: inset 0 0 0 1.5px #F3D48B; }

/* Queue lock — bills behind the first wait their turn */
.pos-queue-lock {
  display: flex; align-items: center; justify-content: center; gap: 7px;
  margin-bottom: 8px; padding: 8px 10px; border-radius: 10px; cursor: pointer;
  background: #FEF3C7; border: 1.5px dashed #D97706; color: #92400E;
  font-size: 12px; font-weight: 700;
  transition: background .15s ease;
}
.pos-queue-lock:hover { background: #FDE68A; }

.pos-cart__head { display: flex; align-items: center; padding: 10px 12px; border-bottom: 1px solid #EEF2F6; }
.pos-cust { padding: 8px 12px 0; }
.pos-addcust {
  flex: 0 0 40px; height: 40px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid #C9D3E0; border-radius: 6px;
  background: #fff; color: #175A8C; cursor: pointer;
  transition: border-color .15s ease, background .15s ease;
}
.pos-addcust:hover { border-color: #175A8C; background: #F1F6FB; }
.pos-addcust:active { transform: translateY(1px); }
.pos-cust-card { width: 380px; max-width: 94vw; }
.pos-lines { flex: 1; padding: 6px 12px; }
.pos-line { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px dashed #EEF2F6; }
.pos-line__name { font-size: 13px; font-weight: 600; color: #1E293B; }
.pos-line__price { font-size: 11px; color: #94A3B8; }
.pos-line__total { font-size: 13px; font-weight: 800; color: #0F172A; min-width: 74px; text-align: end; }
/* Bigger targets: a finger, not a mouse, drives this screen. */
.pos-qty { display: flex; align-items: center; background: #F1F5F9; border-radius: 22px; }
.pos-qty__b { width: 34px; height: 34px; }
.pos-qty__n {
  position: relative; min-width: 34px; height: 34px;
  display: inline-flex; align-items: center; justify-content: center;
  text-align: center; font-weight: 800; font-size: 15px; cursor: pointer;
  border-radius: 10px;
}
.pos-qty__n:hover { background: #E2E8F0; }

/* ── qty keypad ── */
.pos-pad-menu { border-radius: 16px; }
.pos-pad { width: 236px; padding: 12px; }
.pos-pad__head { font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: .05em;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pos-pad__val { font-size: 30px; font-weight: 900; color: #0F172A; text-align: center; padding: 4px 0 10px;
  font-variant-numeric: tabular-nums; }
.pos-pad__grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; }
.pos-pad__k {
  height: 46px; border: 0; border-radius: 11px; background: #F1F5F9; color: #0F172A;
  font-size: 19px; font-weight: 800; cursor: pointer; transition: background .12s ease, transform .1s ease;
}
.pos-pad__k:hover { background: #E2E8F0; }
.pos-pad__k:active { transform: scale(.95); }
.pos-pad__k--alt { background: #FEE2E2; color: #B91C1C; }
.pos-pad__k--ok { background: #16A34A; color: #fff; }

.pos-foot { border-top: 1px solid #E2E8F0; padding: 12px; background: #F8FAFC; }
.pos-foot__row { font-size: 13px; color: #475569; padding: 2px 0; }
.pos-foot__grand { font-size: 18px; font-weight: 800; color: #0F172A; }
.pos-pay { border-radius: 12px; font-weight: 800; }

/* ── the fast lane: one tap finishes a cash sale ── */
.pos-pay-row { display: flex; gap: 6px; margin-top: 8px; }
.pos-cash {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 9px;
  padding: 7px 12px;
  border: 0; border-radius: 12px;
  background: linear-gradient(135deg, #16A34A, #0E7A38);
  color: #fff; cursor: pointer;
  box-shadow: 0 6px 14px -9px rgba(22, 163, 74, .8);
  transition: transform .14s ease, box-shadow .2s ease, filter .2s ease;
}
.pos-cash:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 11px 20px -12px rgba(22, 163, 74, .9); }
.pos-cash:active:not(:disabled) { transform: translateY(1px); }
.pos-cash:disabled { filter: grayscale(.7); opacity: .5; cursor: not-allowed; box-shadow: none; }
.pos-cash__t { flex: 1; min-width: 0; text-align: start; line-height: 1.1; }
.pos-cash__t b { display: block; font-size: 17px; font-weight: 900; letter-spacing: -.4px; font-variant-numeric: tabular-nums; }
.pos-cash__t small { display: block; font-size: 9.5px; font-weight: 700; opacity: .85; text-transform: uppercase; letter-spacing: .06em; }

/* the quiet way to a card, split or a custom amount */
.pos-tender {
  flex: 0 0 auto;
  display: flex; align-items: center; justify-content: center; gap: 5px;
  padding: 0 11px;
  font: inherit; font-size: 11.5px; font-weight: 800;
  border: 1.5px solid #CBD5E1; border-radius: 12px;
  background: #fff; color: #175A8C; cursor: pointer;
  transition: border-color .15s ease, background .15s ease, transform .14s ease;
}
.pos-tender:hover:not(:disabled) { border-color: #175A8C; background: #F1F6FB; transform: translateY(-1px); }
.pos-tender:active:not(:disabled) { transform: translateY(1px); }
.pos-tender:disabled { opacity: .45; cursor: not-allowed; }

/* ── payment sheet ── */
.pos-pay-card { width: 460px; max-width: 96vw; }
.pos-pay-due { display: flex; justify-content: space-between; align-items: baseline; font-size: 15px; color: #475569; }
.pos-pay-due b { color: var(--q-primary); font-size: 26px; font-weight: 900; font-variant-numeric: tabular-nums; }

.pos-meth { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 12px; }
.pos-meth__b {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  padding: 10px 4px; border: 1.5px solid #E2E8F0; border-radius: 12px;
  background: #fff; color: #64748B; font-size: 11.5px; font-weight: 700; cursor: pointer;
  transition: all .15s ease;
}
.pos-meth__b:hover { border-color: #CBD5E1; }
.pos-meth__b--on {
  border-color: var(--q-primary); color: var(--q-primary);
  background: color-mix(in srgb, var(--q-primary) 8%, #fff);
  box-shadow: 0 0 0 1px var(--q-primary) inset;
}

.pos-tend {
  display: flex; align-items: baseline; gap: 8px;
  margin-top: 14px; padding: 8px 14px;
  background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 13px;
}
.pos-tend__l { font-size: 11px; font-weight: 700; color: #94A3B8; text-transform: uppercase; letter-spacing: .06em; }
.pos-tend__i {
  flex: 1; min-width: 0;
  border: 0; background: transparent; outline: none;
  font: inherit; font-size: 30px; font-weight: 900; color: #0F172A;
  text-align: end; font-variant-numeric: tabular-nums;
  -moz-appearance: textfield;
}
.pos-tend__i::-webkit-outer-spin-button, .pos-tend__i::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.pos-tend__c { font-size: 13px; font-weight: 800; color: #94A3B8; }

.pos-notes__l { margin-top: 12px; font-size: 11px; font-weight: 700; color: #94A3B8; text-align: center; }
.pos-notes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 6px; }
.pos-note {
  height: 46px; border: 0; border-radius: 12px;
  background: linear-gradient(150deg, #123A66, #0B2743);
  color: #F6DEA0; font: inherit; font-size: 16px; font-weight: 800;
  cursor: pointer; font-variant-numeric: tabular-nums;
  box-shadow: 0 3px 8px -4px rgba(11, 39, 67, .7);
  transition: transform .12s ease, filter .15s ease;
}
.pos-note:hover { filter: brightness(1.15); transform: translateY(-1px); }
.pos-note:active { transform: translateY(1px) scale(.97); }

.pos-change {
  display: flex; justify-content: space-between; align-items: baseline;
  margin-top: 12px; padding: 8px 14px; border-radius: 12px;
  background: #FEF2F2; color: #DC2626;
  font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
}
.pos-change b { font-size: 28px; font-weight: 900; letter-spacing: -.5px; text-transform: none; font-variant-numeric: tabular-nums; }
.pos-change--ok { background: #F0FDF4; color: #16A34A; }
.pos-done { border-radius: 13px; font-weight: 800; min-width: 200px; }

/* the change to hand back, readable from across the counter */
.pos-give {
  margin-top: 14px; padding: 10px 16px; border-radius: 14px;
  background: #F0FDF4; color: #15803D;
}
.pos-give span { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; opacity: .8; }
.pos-give b { display: block; font-size: 34px; font-weight: 900; letter-spacing: -1px; line-height: 1.1; font-variant-numeric: tabular-nums; }
.pos-give--none { background: #F1F5F9; color: #64748B; }
.pos-give--none b { font-size: 22px; }
.pos-give small { display: block; font-size: 11px; font-weight: 600; }

/* ── what the customer handed over, typed on the receipt ── */
.pos-rgot {
  display: flex; align-items: center; gap: 8px;
  margin-top: 10px; padding: 7px 12px;
  background: #fff; border: 1.5px solid #E2E8F0; border-radius: 12px;
}
.pos-rgot__l { font-size: 9.5px; font-weight: 800; color: #94A3B8; text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
.pos-rgot__i {
  flex: 1; min-width: 0;
  border: 0; background: transparent; outline: none;
  font: inherit; font-size: 20px; font-weight: 900; color: #0F172A;
  text-align: end; font-variant-numeric: tabular-nums;
  -moz-appearance: textfield;
}
.pos-rgot__i::-webkit-outer-spin-button, .pos-rgot__i::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.pos-rgot__i::placeholder { color: #CBD5E1; font-weight: 800; }
.pos-rgot__x {
  border: 0; background: #F1F5F9; color: #64748B;
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center; cursor: pointer;
}
.pos-rgot__x:hover { background: #E2E8F0; }
/* One-touch notes. These SET the amount, so they carry no "+" — a customer
   hands over one note, they do not stack them up. */
.pos-rgot__notes { display: flex; gap: 6px; margin-top: 7px; }
.pos-rgot__note {
  flex: 1 1 0; min-width: 0;
  padding: 8px 4px; border: 1.5px solid #DDE5EE; border-radius: 10px;
  background: #F8FAFC; color: #123A66;
  font: inherit; font-size: 13px; font-weight: 800; cursor: pointer;
  font-variant-numeric: tabular-nums;
  transition: background .12s ease, border-color .12s ease, color .12s ease;
}
.pos-rgot__note:hover { background: #EEF4FA; border-color: #175A8C; }
.pos-rgot__note:active { transform: translateY(1px); }
.pos-rgot__note--on { background: #123A66; border-color: #123A66; color: #fff; }
.pos-rgot__note--exact { background: #E8F5EE; border-color: #BFE3CE; color: #15803D; font-size: 11px; }

.pos-rgot__short {
  margin-top: 6px; padding: 5px 12px; border-radius: 10px;
  background: #FEF2F2; color: #DC2626;
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
}
.pos-rgot__short b { font-size: 15px; letter-spacing: 0; text-transform: none; font-variant-numeric: tabular-nums; }

.pos-receipt { width: 360px; max-width: 92vw; }
.pos-receipt__top { text-align: center; padding: 20px; }
.pos-receipt__body { max-height: 40vh; overflow-y: auto; }
.pos-receipt__line { font-size: 13px; padding: 3px 0; }

@media (max-width: 900px) {
  .pos-root { flex-direction: column; height: auto; }
  .pos-right { width: 100%; }
}


/* ── register settings ── */
.pos-set-card { width: 520px; max-width: 95vw; }
.pos-set__row {
  display: flex; align-items: center; gap: 12px;
  padding: 9px 4px; border-bottom: 1px dashed #EEF2F6;
}
.pos-set__row:last-child { border-bottom: 0; }
.pos-set__name { font-size: 13.5px; font-weight: 700; color: #1E293B; }
.pos-set__hint { font-size: 11px; color: #94A3B8; }
</style>
