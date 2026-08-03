<template>
  <q-page class="ws-page">
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <!-- ══ Business-Class hero — the VIP cabin of the store ══ -->
        <div class="col-12">
          <div class="ws-hero">
            <div class="ws-hero__left">
              <div class="ws-hero__eyebrow"><q-icon name="mdi-diamond-stone" size="13px" /> {{ $t('WholesaleB2B') }}</div>
              <div class="ws-hero__val">{{ fmt(dash.sales_30d) }}</div>
              <div class="ws-hero__lbl">{{ $t('Sales30d') }} · {{ $t('BusinessSales') }}</div>
            </div>
            <div class="ws-hero__stats">
              <div class="ws-hero__stat">
                <div class="ws-hero__ring ws-hero__ring--amber"><q-icon name="account_balance" size="17px" /></div>
                <div>
                  <b class="ws-money">{{ fmt(dash.outstanding) }}</b>
                  <span>{{ $t('OutstandingReceivables') }}</span>
                </div>
              </div>
              <div class="ws-hero__stat">
                <div class="ws-hero__ring"><q-icon name="business" size="17px" /></div>
                <div>
                  <b>{{ dash.customers ?? 0 }}</b>
                  <span>{{ $t('BusinessCustomers') }}</span>
                </div>
              </div>
              <div class="ws-hero__stat">
                <div class="ws-hero__ring"><q-icon name="request_quote" size="17px" /></div>
                <div>
                  <b>{{ dash.open_quotes ?? 0 }}</b>
                  <span>{{ $t('OpenQuotations') }}</span>
                </div>
              </div>
            </div>
            <div class="ws-hero__cta">
              <q-btn unelevated no-caps size="md" icon="add_shopping_cart" :label="$t('NewOrder')" class="ws-hero__btn" @click="tab = 'order'" />
              <q-btn outline no-caps size="md" color="white" icon="business" :label="$t('Customers')" class="q-mt-sm full-width" @click="tab = 'customers'" />
            </div>
          </div>
        </div>

        <div class="col-12">
    <q-card flat bordered class="ws-card three_d_latest">
      <q-tabs v-model="tab" dense no-caps class="ws-tabs" active-color="primary" indicator-color="primary" narrow-indicator align="left">
        <q-tab name="dashboard" icon="insights" :label="$t('Dashboard')" />
        <q-tab name="order" icon="add_shopping_cart" :label="$t('NewOrder')" />
        <q-tab name="orders" icon="receipt_long" :label="$t('Orders')" />
        <q-tab name="customers" icon="business" :label="$t('Customers')" />
      </q-tabs>
      <q-separator />

      <!-- ═══ DASHBOARD ═══ -->
      <div v-show="tab === 'dashboard'" class="q-pa-md">
        <div class="row q-col-gutter-md">
          <div class="col-12 col-lg-4">
            <div class="ws-panel">
              <div class="ws-panel__title"><span class="ws-ring"><q-icon name="emoji_events" size="14px" /></span> {{ $t('TopWholesaleCustomers') }}</div>
              <div v-for="(c, i) in dash.top_customers || []" :key="c.label" class="ws-row">
                <span class="ws-line__rank" :class="'ws-line__rank--' + (i + 1)">{{ i + 1 }}</span>
                <div class="ws-row__body">
                  <div class="ws-row__line">
                    <span class="ws-line__name">{{ c.label }}</span>
                    <b class="ws-line__val">{{ fmt(c.total) }}</b>
                  </div>
                  <div class="ws-bar-track"><div class="ws-bar ws-bar--gold" :style="`width:${sharePct(c.total, dash.top_customers, 'total')}%`"></div></div>
                  <div class="ws-line__sub">{{ c.orders }} {{ $t('Orders') }}</div>
                </div>
              </div>
              <div v-if="!(dash.top_customers || []).length" class="ws-empty">{{ $t('NoRecordFound') }}</div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <div class="ws-panel">
              <div class="ws-panel__title"><span class="ws-ring"><q-icon name="inventory_2" size="14px" /></span> {{ $t('BestWholesaleProducts') }}</div>
              <div v-for="p in dash.best_products || []" :key="p.name" class="ws-row">
                <div class="ws-row__body">
                  <div class="ws-row__line">
                    <span class="ws-line__name">{{ p.name }}</span>
                    <b class="ws-line__val">{{ fmt(p.revenue) }}</b>
                  </div>
                  <div class="ws-bar-track"><div class="ws-bar" :style="`width:${sharePct(p.revenue, dash.best_products, 'revenue')}%`"></div></div>
                  <div class="ws-line__sub">× {{ Number(p.qty) }}</div>
                </div>
              </div>
              <div v-if="!(dash.best_products || []).length" class="ws-empty">{{ $t('NoRecordFound') }}</div>
            </div>
          </div>
          <div class="col-12 col-lg-4">
            <div class="ws-panel">
              <div class="ws-panel__title"><span class="ws-ring"><q-icon name="donut_small" size="14px" /></span> {{ $t('PaymentStatus') }}</div>
              <div class="ws-pay">
                <div class="ws-pay__tile ws-pay__tile--paid">
                  <b>{{ dash.payment_status?.paid ?? 0 }}</b><span>{{ $t('FullyPaid') }}</span>
                </div>
                <div class="ws-pay__tile ws-pay__tile--due">
                  <b>{{ dash.payment_status?.partial ?? 0 }}</b><span>{{ $t('WithBalance') }}</span>
                </div>
              </div>
              <div class="ws-pay-track">
                <div class="ws-pay-track__paid" :style="`width:${paidPct}%`"></div>
              </div>
              <div class="ws-panel__title q-mt-md"><span class="ws-ring"><q-icon name="calendar_month" size="14px" /></span> {{ $t('MonthlySales') }}</div>
              <div class="ws-months">
                <div v-for="m in dash.monthly || []" :key="m.m" class="ws-months__col">
                  <div class="ws-months__bar" :style="`height:${monthPct(m)}%`"><q-tooltip>{{ m.m }} · {{ fmt(m.total) }}</q-tooltip></div>
                  <span>{{ m.m.slice(5) }}</span>
                </div>
                <div v-if="!(dash.monthly || []).length" class="ws-empty">{{ $t('NoRecordFound') }}</div>
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="ws-panel">
              <div class="ws-panel__title"><q-icon name="schedule" size="15px" /> {{ $t('RecentOrders') }}</div>
              <div v-for="o in dash.recent_orders || []" :key="o.invoice_no" class="ws-line">
                <b class="ws-line__name" style="flex:0 0 110px">{{ o.invoice_no }}</b>
                <span class="ws-line__name">{{ o.customer }}</span>
                <span class="ws-line__sub">{{ o.date }}</span>
                <q-badge v-if="o.outstanding > 0" color="orange-8">{{ $t('Due') }} <span class="ws-money">{{ fmt(o.outstanding) }}</span></q-badge>
                <b class="ws-line__val">{{ fmt(o.total) }}</b>
              </div>
              <div v-if="!(dash.recent_orders || []).length" class="ws-empty">{{ $t('NoRecordFound') }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ NEW ORDER ═══ -->
      <div v-show="tab === 'order'" class="q-pa-md">
        <div class="row q-col-gutter-md">
          <div class="col-12 col-md-7">
            <q-select outlined dense v-model="order.customer" :options="customerOptions" emit-value map-options :label="$t('BusinessCustomer')" class="q-mb-sm">
              <template #prepend><q-icon name="business" color="amber-9" /></template>
              <template #selected-item="scope">
                <span>{{ scope.opt.label ?? (scope.opt.company_name || scope.opt.name) }}</span>
              </template>
            </q-select>
            <q-banner v-if="order.customer" dense rounded class="bg-blue-grey-1 text-blue-grey-9 q-mb-sm">
              {{ $t('Balance') }}: <b class="ws-money" :class="order.customer.balance > 0 ? 'text-orange-9' : 'text-green-8'">{{ fmt(order.customer.balance) }}</b>
              · {{ $t('CreditLimit') }}: <span class="ws-money">{{ fmt(order.customer.credit_limit) }}</span>
              <template v-if="order.customer.payment_terms"> · {{ order.customer.payment_terms }}</template>
            </q-banner>

            <q-input outlined dense v-model="prodSearch" :label="$t('SearchOrScan')" debounce="300" @update:model-value="loadCatalog" class="q-mb-sm">
              <template #prepend><q-icon name="qr_code_scanner" color="amber-9" /></template>
            </q-input>
            <div class="ws-cat">
              <div v-for="p in catalog" :key="p.id" class="ws-cat__item" @click="addLine(p)">
                <div class="ws-cat__img">
                  <img v-if="p.image_url" :src="assetUrl(p.image_url)" :alt="p.name">
                  <q-icon v-else name="inventory_2" size="14px" color="blue-grey-4" />
                </div>
                <div class="min-w-0">
                  <div class="ws-cat__name">{{ p.name }}</div>
                  <div class="ws-cat__meta">
                    <s class="text-grey-5">{{ fmtN(p.retail_price) }}</s>
                    <b class="text-amber-9 q-ml-xs">{{ fmtN(p.wholesale_price) }}</b>
                    <q-badge v-if="!p.has_wholesale_price" color="blue-grey-2" text-color="blue-grey-8" class="q-ml-xs">{{ $t('RetailFallback') }}</q-badge>
                    <span class="q-ml-xs text-grey-6">· {{ p.stock_qty }} {{ $t('InStockShort') }}</span>
                  </div>
                </div>
                <q-icon name="add_circle" color="amber-8" size="20px" />
              </div>
            </div>
          </div>

          <div class="col-12 col-md-5">
            <div class="ws-cart">
              <div class="ws-panel__title"><q-icon name="shopping_cart" size="15px" /> {{ $t('OrderLines') }}</div>
              <div v-if="!order.lines.length" class="ws-empty q-py-lg">{{ $t('AddProductsFromCatalog') }}</div>
              <div v-for="(l, i) in order.lines" :key="l.product_id" class="ws-cart__line">
                <div class="min-w-0">
                  <div class="ws-cat__name">{{ l.name }}</div>
                  <div class="ws-cat__meta">{{ $t('Wholesale') }}: {{ fmtN(l.base_price) }}</div>
                </div>
                <q-input outlined dense type="number" min="1" v-model.number="l.qty" class="ws-cart__qty" :label="$t('Qty')" />
                <q-input outlined dense type="number" step="0.01" min="0" v-model.number="l.unit_price" class="ws-cart__price" :label="$t('Price')" />
                <q-btn dense flat round size="sm" icon="close" color="red-5" @click="order.lines.splice(i, 1)" />
              </div>

              <template v-if="order.lines.length">
                <q-separator class="q-my-sm" />
                <div class="ws-tot"><span>{{ $t('Subtotal') }}</span><b class="ws-money">{{ fmt(orderSubtotal) }}</b></div>
                <div class="ws-tot">
                  <span>{{ $t('NegotiatedDiscount') }}</span>
                  <q-input outlined dense type="number" min="0" v-model.number="order.discount" style="width:120px" />
                </div>
                <div class="ws-tot ws-tot--grand"><span>{{ $t('Total') }}</span><b class="ws-money">{{ fmt(orderTotal) }}</b></div>
                <div class="ws-tot">
                  <span>{{ $t('PaidNow') }}</span>
                  <q-input outlined dense type="number" min="0" v-model.number="order.paid" style="width:120px" />
                </div>
                <div class="ws-tot"><span>{{ $t('RemainsOnCredit') }}</span><b class="ws-money text-orange-9">{{ fmt(Math.max(0, orderTotal - (order.paid || 0))) }}</b></div>
                <q-input outlined dense v-model="order.note" :label="$t('Note')" class="q-mt-sm" />
                <div class="row q-col-gutter-sm q-mt-sm">
                  <div class="col-6">
                    <q-btn outline no-caps color="blue-grey-7" icon="request_quote" :label="$t('SaveQuotation')" class="full-width" :loading="savingQuote" :disable="!order.customer" @click="submitOrder('quote')" />
                  </div>
                  <div class="col-6">
                    <q-btn unelevated no-caps class="ws-invoice-btn full-width" icon="receipt_long" :label="$t('CreateInvoice')" :loading="savingInvoice" :disable="!order.customer" @click="submitOrder('invoice')" />
                  </div>
                </div>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ ORDERS ═══ -->
      <div v-show="tab === 'orders'" class="q-pa-md">
        <div class="row q-col-gutter-sm q-mb-md">
          <div class="col-6 col-md-3">
            <q-select outlined dense v-model="of.status" :options="[
              { label: $t('Invoices'), value: 'completed' },
              { label: $t('Quotations'), value: 'quote' },
            ]" emit-value map-options :label="$t('Status')" clearable @update:model-value="loadOrders" />
          </div>
          <div class="col-6 col-md-4">
            <q-select outlined dense v-model="of.customer_id" :options="customerOptions.map(o => ({ label: o.label, value: o.value.id }))" emit-value map-options :label="$t('Customer')" clearable @update:model-value="loadOrders" />
          </div>
        </div>
        <q-markup-table flat dense class="ws-table">
          <thead>
            <tr>
              <th class="text-left">#</th>
              <th class="text-left">{{ $t('Customer') }}</th>
              <th class="text-left">{{ $t('Status') }}</th>
              <th class="text-right">{{ $t('Total') }}</th>
              <th class="text-right">{{ $t('Paid') }}</th>
              <th class="text-right">{{ $t('Outstanding') }}</th>
              <th class="text-left">{{ $t('Time') }}</th>
              <th class="text-right">{{ $t('Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!orders.length"><td colspan="8" class="text-center text-grey-5">{{ $t('NoRecordFound') }}</td></tr>
            <tr v-for="o in orders" :key="o.id">
              <td class="text-weight-bold">{{ o.invoice_no }}</td>
              <td>{{ o.customer }}</td>
              <td>
                <q-chip dense square size="sm" :color="o.status === 'quote' ? 'blue-grey-6' : (o.outstanding > 0 ? 'orange-8' : 'green-7')" text-color="white" class="q-ma-none">
                  {{ o.status === 'quote' ? $t('Quotation') : (o.outstanding > 0 ? $t('PartiallyPaid') : $t('Paid')) }}
                </q-chip>
              </td>
              <td class="text-right ws-money">{{ fmt(o.total) }}</td>
              <td class="text-right ws-money">{{ fmt(o.paid) }}</td>
              <td class="text-right ws-money" :class="o.outstanding > 0 ? 'text-orange-9 text-weight-bold' : ''">{{ fmt(o.outstanding) }}</td>
              <td>{{ o.date }}</td>
              <td class="text-right" style="white-space:nowrap">
                <q-btn v-if="o.status === 'quote'" dense flat no-caps size="sm" color="amber-9" icon="task_alt" :label="$t('Convert')" @click="convertQuote(o)" />
                <q-btn v-else-if="o.outstanding > 0" dense flat no-caps size="sm" color="green-7" icon="payments" :label="$t('RecordPayment')" @click="openPay(o)" />
              </td>
            </tr>
          </tbody>
        </q-markup-table>
      </div>

      <!-- ═══ CUSTOMERS ═══ -->
      <div v-show="tab === 'customers'" class="q-pa-md">
        <div class="row items-center q-mb-md">
          <q-input outlined dense v-model="custSearch" :label="$t('Search')" clearable debounce="300" @update:model-value="loadCustomers" style="min-width:240px">
            <template #prepend><q-icon name="search" color="amber-9" /></template>
          </q-input>
          <q-space />
          <q-btn v-if="$can('wholesale-create')" unelevated no-caps class="ws-invoice-btn" icon="add_business" :label="$t('AddBusinessCustomer')" @click="openCustomer()" />
        </div>
        <div class="row q-col-gutter-md">
          <div v-for="c in customers" :key="c.id" class="col-12 col-md-6 col-lg-4">
            <!-- The account card: a business customer is a standing account,
                 not a row in a list, so it gets the same elevated treatment as
                 the module's stat cards — a tier ribbon read at a glance, the
                 three figures that matter in one line, credit exposure as a
                 single bar rather than a paragraph. -->
            <div class="ws-cust three_d_latest" :class="`ws-cust--${tierOf(c).key}`">
              <div class="ws-cust__top">
                <div class="ws-cust__avatar">{{ initials(c) }}</div>
                <div class="col min-w-0">
                  <div class="ws-cust__name">{{ c.company_name || c.name }}</div>
                  <div class="ws-cust__meta">{{ c.contact_person || c.name }} · {{ c.phone || '—' }}</div>
                </div>
                <span class="ws-cust__dot" :class="c.active ? 'ws-cust__dot--on' : 'ws-cust__dot--off'">
                  <q-tooltip>{{ c.active ? $t('Active') : $t('Inactive') }}</q-tooltip>
                </span>
              </div>

              <div class="ws-cust__tier" :class="`ws-cust__tier--${tierOf(c).key}`">
                <q-icon name="workspace_premium" size="12px" /> {{ $t(tierOf(c).label) }}
              </div>

              <div class="ws-cust__nums">
                <div><span>{{ $t('Balance') }}</span><b :class="Number(c.balance) > 0 ? 'text-orange-9' : 'text-green-8'">{{ fmt(c.balance) }}</b></div>
                <div><span>{{ $t('CreditLimit') }}</span><b>{{ fmt(c.credit_limit) }}</b></div>
                <div><span>{{ $t('LifetimeSpend') }}</span><b>{{ fmt(c.total_spent) }}</b></div>
              </div>

              <div class="ws-cust__credit">
                <div class="ws-cust__track"><div class="ws-cust__bar" :class="{ 'ws-cust__bar--over': creditPct(c) >= 100 }" :style="`width:${Math.min(100, creditPct(c))}%`"></div></div>
                <span :class="{ 'text-negative text-weight-bold': creditPct(c) >= 100 }">
                  <q-icon v-if="creditPct(c) >= 100" name="warning" size="11px" class="q-mr-2xs" />
                  {{ creditPct(c) }}% {{ $t('OfCredit') }}
                </span>
              </div>

              <q-separator class="ws-cust__sep" />
              <div class="row justify-end q-gutter-xs">
                <q-btn dense flat no-caps size="sm" color="primary" icon="menu_book" :label="$t('Ledger')" @click="openLedger(c)" />
                <q-btn v-if="$can('wholesale-edit')" dense flat no-caps size="sm" color="blue-grey-7" icon="edit" :label="$t('Edit')" @click="openCustomer(c)" />
              </div>
            </div>
          </div>
          <div v-if="!customers.length" class="col-12 text-center text-grey-5 q-py-xl">
            <q-icon name="add_business" size="34px" color="amber-8" /><br>{{ $t('NoRecordFound') }}
          </div>
        </div>
      </div>
    </q-card>
        </div>
      </div>
    </m-backgrounds>

    <!-- Customer form dialog -->
    <q-dialog v-model="custDlg">
      <q-card class="ws-dlg">
        <q-card-section class="text-weight-bold">{{ custForm.id ? $t('Edit') : $t('AddBusinessCustomer') }}</q-card-section>
        <q-separator />
        <q-card-section class="row q-col-gutter-sm">
          <div class="col-12 col-sm-6"><q-input outlined dense v-model="custForm.company_name" :label="$t('CompanyName')" /></div>
          <div class="col-12 col-sm-6"><q-input outlined dense v-model="custForm.name" :label="$t('ContactPerson') + ' *'" /></div>
          <div class="col-6"><q-input outlined dense v-model="custForm.phone" :label="$t('Phone')" /></div>
          <div class="col-6"><q-input outlined dense v-model="custForm.email" :label="$t('Email')" /></div>
          <div class="col-12"><q-input outlined dense v-model="custForm.address" :label="$t('Address')" /></div>
          <div class="col-6 col-sm-4"><q-input outlined dense v-model="custForm.tax_number" :label="$t('TaxNumber')" /></div>
          <div class="col-6 col-sm-4"><q-input outlined dense type="number" min="0" v-model.number="custForm.credit_limit" :label="$t('CreditLimit')" /></div>
          <div class="col-12 col-sm-4">
            <q-select outlined dense v-model="custForm.payment_terms" :options="['cash', 'net-7', 'net-15', 'net-30']" :label="$t('PaymentTerms')" clearable />
          </div>
          <div class="col-12"><q-input outlined dense v-model="custForm.notes" :label="$t('Notes')" autogrow /></div>
          <div class="col-12"><q-toggle v-model="custForm.active" :label="$t('Active')" color="amber-9" /></div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps class="ws-invoice-btn" :label="$t('Save')" :loading="custSaving" :disable="!custForm.name" @click="saveCustomer" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Ledger dialog -->
    <q-dialog v-model="ledgerDlg">
      <q-card class="ws-dlg" style="min-width:560px;max-width:95vw">
        <q-card-section class="row items-center">
          <div class="ws-cust__avatar"><q-icon name="business" size="18px" /></div>
          <div class="q-ml-sm">
            <div class="text-weight-bold">{{ ledger.customer?.company_name || ledger.customer?.name }}</div>
            <div class="text-caption text-grey-7">{{ $t('Balance') }}: <b class="ws-money text-orange-9">{{ fmt(ledger.customer?.balance) }}</b></div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-section style="max-height:420px;overflow-y:auto">
          <div v-for="o in ledger.orders || []" :key="o.id" class="ws-line">
            <b style="flex:0 0 100px">{{ o.invoice_no }}</b>
            <q-chip dense square size="sm" :color="o.status === 'quote' ? 'blue-grey-6' : (o.outstanding > 0 ? 'orange-8' : 'green-7')" text-color="white" class="q-ma-none">
              {{ o.status === 'quote' ? $t('Quotation') : (o.outstanding > 0 ? $t('Due') + ' ' + fmtN(o.outstanding) : $t('Paid')) }}
            </q-chip>
            <span class="ws-line__sub">{{ o.date }}</span>
            <b class="ws-line__val">{{ fmt(o.total) }}</b>
          </div>
          <div v-if="!(ledger.orders || []).length" class="ws-empty">{{ $t('NoRecordFound') }}</div>
        </q-card-section>
      </q-card>
    </q-dialog>

    <!-- Payment dialog -->
    <q-dialog v-model="payDlg">
      <q-card class="ws-dlg">
        <q-card-section class="text-weight-bold">{{ $t('RecordPayment') }} — {{ payTarget?.invoice_no }}</q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm">
          <q-banner dense rounded class="bg-orange-1 text-orange-10">{{ $t('Outstanding') }}: <b class="ws-money">{{ fmt(payTarget?.outstanding) }}</b></q-banner>
          <q-input outlined dense type="number" min="1" v-model.number="payAmount" :label="$t('Amount')" autofocus />
          <q-select outlined dense v-model="payMethod" :options="['cash', 'card', 'mobile']" :label="$t('Method')" />
        </q-card-section>
        <q-separator />
        <q-card-actions align="right">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="green-7" icon="payments" :label="$t('RecordPayment')" :loading="paySaving" :disable="!Number(payAmount)" @click="savePayment" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api, assetUrl } from '@/boot/axios'

const tab = ref('dashboard')

function fmt (v) { return (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' AFN' }
function fmtN (v) { return (Number(v) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) }

// ── Dashboard ──
const dash = ref({})
async function loadDash () {
  try { dash.value = (await api.get('/wholesale/dashboard')).data } catch (_) {}
}
const maxMonth = computed(() => Math.max(...(dash.value.monthly || []).map(m => Number(m.total)), 1))
function monthPct (m) { return Math.max(8, Math.round(Number(m.total) / maxMonth.value * 100)) }

/** A row's share of the biggest value in its list — drives the ranking bars. */
function sharePct (val, list, key) {
  const max = Math.max(1, ...(list || []).map(x => Number(x[key]) || 0))
  return Math.max(4, Math.round((Number(val) || 0) / max * 100))
}
const paidPct = computed(() => {
  const p = Number(dash.value.payment_status?.paid ?? 0)
  const d = Number(dash.value.payment_status?.partial ?? 0)
  return p + d > 0 ? Math.round(p / (p + d) * 100) : 100
})

// ── Customers ──
const customers = ref([])
const custSearch = ref('')
const customerOptions = computed(() => customers.value.filter(c => c.active).map(c => ({ label: (c.company_name || c.name), value: c })))
async function loadCustomers () {
  try {
    const { data } = await api.get('/wholesale/customers', { params: custSearch.value ? { search: custSearch.value } : {} })
    customers.value = data
  } catch (_) {}
}
function creditPct (c) {
  const lim = Number(c.credit_limit) || 0
  return lim > 0 ? Math.round(Number(c.balance) / lim * 100) : 0
}

/**
 * Account tier, read off lifetime spend. Purely a display band — nothing is
 * stored, nothing gates on it — the same way an airline lounge chip does not
 * change what the ticket paid for. Bands sit far above the retail loyalty
 * tiers because a single wholesale order routinely exceeds a retail
 * customer's yearly total.
 */
const TIERS = [
  { key: 'platinum', min: 1000000, label: 'TierPlatinumPartner' },
  { key: 'gold', min: 400000, label: 'TierGoldAccount' },
  { key: 'silver', min: 100000, label: 'TierSilverAccount' },
  { key: 'standard', min: 0, label: 'TierStandardAccount' },
]
function tierOf (c) {
  const spend = Number(c.total_spent) || 0
  return TIERS.find(t => spend >= t.min)
}

/** Up to two letters for the monogram badge — company name if there is one. */
function initials (c) {
  const src = (c.company_name || c.name || '?').trim()
  const parts = src.split(/\s+/).filter(Boolean)
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || src[0]?.toUpperCase() || '?'
}

const custDlg = ref(false)
const custSaving = ref(false)
const blankCust = () => ({ id: null, name: '', company_name: '', contact_person: '', phone: '', email: '', address: '', tax_number: '', credit_limit: 0, payment_terms: null, notes: '', active: true })
const custForm = reactive(blankCust())
function openCustomer (c = null) {
  Object.assign(custForm, blankCust(), c ? { ...c, credit_limit: Number(c.credit_limit) } : {})
  custDlg.value = true
}
async function saveCustomer () {
  custSaving.value = true
  try {
    const payload = { ...custForm, contact_person: custForm.contact_person || custForm.name }
    if (custForm.id) await api.put('/wholesale/customers/' + custForm.id, payload)
    else await api.post('/wholesale/customers', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    custDlg.value = false
    loadCustomers(); loadDash()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { custSaving.value = false }
}

const ledgerDlg = ref(false)
const ledger = ref({})
async function openLedger (c) {
  ledger.value = {}
  ledgerDlg.value = true
  try { ledger.value = (await api.get(`/wholesale/customers/${c.id}/ledger`)).data } catch (_) {}
}

// ── New order ──
const catalog = ref([])
const prodSearch = ref('')
const order = reactive({ customer: null, lines: [], discount: 0, paid: 0, note: '' })
const savingInvoice = ref(false)
const savingQuote = ref(false)

async function loadCatalog () {
  try {
    const { data } = await api.get('/wholesale/catalog', { params: prodSearch.value ? { search: prodSearch.value } : {} })
    catalog.value = data
  } catch (_) {}
}
function addLine (p) {
  const found = order.lines.find(l => l.product_id === p.id)
  if (found) { found.qty = Number(found.qty) + 1; return }
  order.lines.push({ product_id: p.id, name: p.name, base_price: p.wholesale_price, unit_price: p.wholesale_price, qty: 1 })
}
const orderSubtotal = computed(() => order.lines.reduce((s, l) => s + (Number(l.unit_price) || 0) * (Number(l.qty) || 0), 0))
const orderTotal = computed(() => Math.max(0, orderSubtotal.value - (Number(order.discount) || 0)))

async function submitOrder (kind) {
  if (!order.customer || !order.lines.length) return
  const flag = kind === 'quote' ? savingQuote : savingInvoice
  flag.value = true
  try {
    const { data } = await api.post('/wholesale/orders', {
      customer_id: order.customer.id,
      kind,
      discount: Number(order.discount) || 0,
      paid: Number(order.paid) || 0,
      method: 'cash',
      note: order.note || null,
      items: order.lines.map(l => ({ product_id: l.product_id, qty: Number(l.qty), unit_price: Number(l.unit_price) })),
    })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'receipt_long', message: `${data.invoice_no} ${kind === 'quote' ? 'saved as quotation' : 'created'}` })
    Object.assign(order, { customer: order.customer, lines: [], discount: 0, paid: 0, note: '' })
    loadDash(); loadOrders(); loadCustomers(); loadCatalog()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  } finally { flag.value = false }
}

// ── Orders ──
const orders = ref([])
const of = reactive({ status: null, customer_id: null })
async function loadOrders () {
  try {
    const params = {}
    if (of.status) params.status = of.status
    if (of.customer_id) params.customer_id = of.customer_id
    orders.value = (await api.get('/wholesale/orders', { params })).data
  } catch (_) {}
}
async function convertQuote (o) {
  try {
    await api.post(`/wholesale/orders/${o.id}/convert`)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'task_alt', message: `${o.invoice_no} converted to invoice` })
    loadOrders(); loadDash(); loadCustomers()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Convert failed' })
  }
}

const payDlg = ref(false)
const payTarget = ref(null)
const payAmount = ref(null)
const payMethod = ref('cash')
const paySaving = ref(false)
function openPay (o) { payTarget.value = o; payAmount.value = o.outstanding; payMethod.value = 'cash'; payDlg.value = true }
async function savePayment () {
  paySaving.value = true
  try {
    await api.post(`/wholesale/orders/${payTarget.value.id}/payment`, { amount: Number(payAmount.value), method: payMethod.value })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'payments', message: 'Payment recorded' })
    payDlg.value = false
    loadOrders(); loadDash(); loadCustomers()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Payment failed' })
  } finally { paySaving.value = false }
}

onMounted(() => { loadDash(); loadCustomers(); loadCatalog(); loadOrders() })
</script>

<style scoped>
.ws-page { background: #F6F8FB; }

/* ══ Business-Class hero — deep navy, gold trim, the VIP cabin ══ */
.ws-hero {
  position: relative; display: flex; align-items: stretch; gap: 24px; flex-wrap: wrap;
  background: linear-gradient(118deg, #0E2A47 0%, #123A66 52%, #17517F 100%);
  border-radius: 18px; padding: 22px 26px; color: #fff; overflow: hidden;
  box-shadow: 0 24px 44px -26px rgba(14, 42, 71, 0.9);
}
.ws-hero::before {
  content: ''; position: absolute; width: 360px; height: 360px; border-radius: 50%;
  background: radial-gradient(circle, rgba(243, 212, 139, 0.15), transparent 65%);
  top: -150px; inset-inline-end: -70px; pointer-events: none;
}
.ws-hero::after {
  content: ''; position: absolute; inset-inline: 0; bottom: 0; height: 3px;
  background: linear-gradient(90deg, transparent, #C8862D 30%, #F3D48B 50%, #C8862D 70%, transparent);
}
.ws-hero__left { flex: 1 1 240px; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.ws-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10.5px; font-weight: 800; letter-spacing: 2.2px; text-transform: uppercase;
  color: #F3D48B; margin-bottom: 6px;
}
.ws-hero__val { font-size: 34px; font-weight: 900; letter-spacing: -0.8px; line-height: 1.05; font-variant-numeric: tabular-nums; }
.ws-hero__lbl { font-size: 11.5px; color: #9FC1E0; margin-top: 4px; }
.ws-hero__stats { flex: 1.2 1 300px; display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
.ws-hero__stat { display: flex; align-items: center; gap: 10px; }
.ws-hero__stat b { display: block; font-size: 17px; font-weight: 800; letter-spacing: -0.3px; font-variant-numeric: tabular-nums; }
.ws-hero__stat span { display: block; font-size: 10px; color: #9FC1E0; margin-top: 1px; }
.ws-hero__ring {
  width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(255, 255, 255, 0.09); border: 1px solid rgba(255, 255, 255, 0.16); color: #C7DCEF;
}
.ws-hero__ring--amber { border-color: rgba(243, 212, 139, 0.5); color: #F3D48B; }
.ws-hero__cta { display: flex; flex-direction: column; justify-content: center; min-width: 180px; }
.ws-hero__btn {
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
  font-weight: 900; border-radius: 12px;
}

/* Panel title gold-ring icon + ranked share bars */
.ws-ring {
  width: 26px; height: 26px; border-radius: 9px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(233, 180, 76, 0.13); border: 1.5px solid rgba(200, 134, 45, 0.45); color: #B45309;
}
.ws-row { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px dashed #F1F5F9; }
.ws-row__body { flex: 1; min-width: 0; }
.ws-row__line { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; }
.ws-bar-track { height: 5px; border-radius: 4px; background: #F1F5F9; overflow: hidden; margin-top: 4px; }
.ws-bar { height: 100%; border-radius: 4px; background: linear-gradient(90deg, #2E7CC4, #175A8C); transition: width .5s ease; }
.ws-bar--gold { background: linear-gradient(90deg, #F3D48B, #C8862D); }

/* Payment status tiles + paid ratio */
.ws-pay__tile {
  flex: 1; border-radius: 12px; padding: 10px 12px; text-align: center;
}
.ws-pay__tile b { font-size: 22px; display: block; font-variant-numeric: tabular-nums; }
.ws-pay__tile span { font-size: 10.5px; }
.ws-pay__tile--paid { background: #ECFDF5; color: #047857; }
.ws-pay__tile--due { background: #FFFBEB; color: #B45309; }
.ws-pay-track { height: 7px; border-radius: 6px; background: #FDE68A; overflow: hidden; margin-top: 8px; }
.ws-pay-track__paid { height: 100%; border-radius: 6px; background: #10B981; transition: width .5s ease; }


.ws-card { border-radius: 18px; border: 1px solid #E9EDF3; overflow: hidden; }
.ws-tabs { background: #fff; }

/* Panels & lines — a thin navy-to-gold rule along the top is the one motif
   repeated across every card on this page, the way a boarding-pass stripe
   marks every card in a set as belonging to the same cabin. */
.ws-panel {
  position: relative; background: #fff; border: 1px solid #EEF2F7; border-radius: 14px;
  padding: 16px 16px 14px; height: 100%; overflow: hidden;
}
.ws-panel::before {
  content: ''; position: absolute; inset-inline: 0; top: 0; height: 3px;
  background: linear-gradient(90deg, #0E2A47, #175A8C 55%, #C8862D);
}
.ws-panel__title { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 800; color: #175A8C; margin-bottom: 8px; }
.ws-line { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px dashed #F1F5F9; font-size: 12.5px; }
.ws-line__rank {
  width: 22px; height: 22px; border-radius: 7px; font-size: 11px; font-weight: 800; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; background: #F1F5F9; color: #64748B;
}
.ws-line__rank--1 { background: #FEF3C7; color: #B45309; }
.ws-line__rank--2 { background: #E2E8F0; color: #475569; }
.ws-line__rank--3 { background: #FFEDD5; color: #C2410C; }
.ws-line__name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: #0F172A; }
.ws-line__sub { font-size: 10.5px; color: #94A3B8; white-space: nowrap; }
.ws-line__val { white-space: nowrap; color: #0E2A47; }
.ws-empty { font-size: 12px; color: #94A3B8; text-align: center; padding: 12px 0; }

.ws-pay { display: flex; gap: 20px; }
.ws-pay__item b { font-size: 20px; display: block; }
.ws-pay__item span { font-size: 10.5px; color: #94A3B8; }
.ws-months { display: flex; align-items: flex-end; gap: 8px; height: 110px; padding-top: 4px; }
/* Capped width so a single month reads as a bar, not a slab. */
.ws-months__col { flex: 1; max-width: 40px; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; }
.ws-months__bar { width: 100%; min-height: 3px; border-radius: 5px 5px 0 0; background: linear-gradient(180deg, #2E7CC4, #0E2A47); }
.ws-months__bar:hover { filter: brightness(1.2); }
.ws-months__col span { font-size: 9.5px; color: #94A3B8; margin-top: 3px; }

/* New order */
.ws-cat { border: 1px solid #EEF2F7; border-radius: 12px; max-height: 430px; overflow-y: auto; }
.ws-cat__item { display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-bottom: 1px solid #F5F8FB; cursor: pointer; }
.ws-cat__item:hover { background: #FBF8F0; }
.ws-cat__img {
  width: 34px; height: 34px; border-radius: 9px; overflow: hidden; flex-shrink: 0;
  border: 1px solid #E9EDF3; background: #F8FAFC; display: flex; align-items: center; justify-content: center;
}
.ws-cat__img img { width: 100%; height: 100%; object-fit: cover; }
.ws-cat__name { font-size: 12.5px; font-weight: 700; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ws-cat__meta { font-size: 10.5px; color: #94A3B8; }
.ws-cart { background: #fff; border: 1px solid #EEF2F7; border-radius: 14px; padding: 14px 16px; }
.ws-cart__line { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px dashed #F1F5F9; }
.ws-cart__qty { width: 74px; }
.ws-cart__price { width: 100px; }
.ws-tot { display: flex; align-items: center; justify-content: space-between; font-size: 12.5px; color: #475569; padding: 3px 0; }
.ws-tot--grand { font-size: 15px; font-weight: 900; color: #0E2A47; }
.ws-invoice-btn { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; font-weight: 800; border-radius: 10px; }

/* Orders table */
.ws-table { border: 1px solid #EEF2F7; border-radius: 12px; }
.ws-table thead tr { background: #F8FAFC; }
.ws-table th { font-size: 10.5px; font-weight: 800; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; }
.ws-table td { font-size: 12.5px; }

/* ── Business account cards — "VIP Business Class" ─────────────
   A wholesale customer is a standing account with credit exposure, not a
   contact row, so the treatment borrows from a premium card product: a navy
   field, a gold monogram ring, a tier ribbon read before anything else, and
   the three figures that matter set in tabular numerals on one line. */
.ws-cust {
  position: relative; background: #fff; border: 1px solid #E9EDF3; border-radius: 16px;
  padding: 16px 16px 13px; height: 100%; overflow: hidden;
  transition: transform 0.22s ease, border-color 0.22s ease;
}
.ws-cust:hover { transform: translateY(-3px); border-color: #C8862D; }
.ws-cust::before {
  content: ''; position: absolute; inset-inline: 0; top: 0; height: 4px;
  background: linear-gradient(90deg, #0E2A47, #175A8C 55%, #C8862D);
}
.ws-cust__top { display: flex; align-items: flex-start; gap: 10px; }
.ws-cust__avatar {
  width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  border: 1.5px solid #F3D48B; box-shadow: inset 0 0 0 3px rgba(243, 212, 139, 0.15);
  font-size: 15px; font-weight: 900; letter-spacing: 0.02em;
}
.ws-cust__name { font-size: 13.5px; font-weight: 800; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ws-cust__meta { font-size: 10.5px; color: #94A3B8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* A dot instead of a chip: the status that matters least gets the smallest
   treatment, so it does not compete with the tier ribbon for attention. */
.ws-cust__dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; }
.ws-cust__dot--on { background: #22C55E; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.18); }
.ws-cust__dot--off { background: #CBD5E1; }

.ws-cust__tier {
  display: inline-flex; align-items: center; gap: 4px; margin-top: 10px;
  padding: 3px 9px; border-radius: 999px;
  font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.07em;
}
.ws-cust__tier--standard { background: #F1F5F9; color: #64748B; }
.ws-cust__tier--silver { background: linear-gradient(90deg, #E5E9F0, #CBD5E1); color: #334155; }
.ws-cust__tier--gold { background: linear-gradient(90deg, #FDE9B8, #F3D48B); color: #7C4A03; }
.ws-cust__tier--platinum { background: linear-gradient(90deg, #0E2A47, #175A8C); color: #F3D48B; }

.ws-cust__nums { display: flex; gap: 14px; margin: 11px 0 7px; }
.ws-cust__nums > div { flex: 1; min-width: 0; }
.ws-cust__nums span {
  display: block; font-size: 9px; font-weight: 700; color: #94A3B8;
  text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap;
}
.ws-cust__nums b { display: block; font-size: 13px; color: #0F172A; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.ws-cust__credit { display: flex; align-items: center; gap: 8px; font-size: 10px; color: #94A3B8; }
.ws-cust__track { flex: 1; height: 6px; border-radius: 5px; background: #F1F5F9; overflow: hidden; }
.ws-cust__bar { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #17517F, #C8862D); }
.ws-cust__bar--over { background: #EF4444; }
.ws-cust__sep { margin: 10px 0 6px; }

.ws-dlg { min-width: 420px; max-width: 95vw; border-radius: 14px; }
.min-w-0 { min-width: 0; }
</style>
