<template>
  <!-- Armed indicator: small, always-on proof that scanning will work here
       without touching anything. Clicking it opens manual entry for the days
       the scanner is unplugged or a barcode is torn. -->
  <div class="scn" :class="{ 'scn--busy': busy }">
    <button type="button" class="scn__chip" @click="manualOpen = true">
      <q-icon :name="busy ? 'hourglass_top' : 'qr_code_scanner'" size="16px" />
      <span class="scn__txt">{{ busy ? $t('Loading') : $t('ScanModeOn') }}</span>
      <span class="scn__dot"></span>
      <q-tooltip>{{ $t('ScanHint') }}</q-tooltip>
    </button>
    <span v-if="lastLabel" class="scn__last">{{ lastLabel }}</span>
  </div>

  <!-- Several products carry the same code: the human decides, then scanning resumes. -->
  <m-modal :showCM="chooseOpen" @update:showCM="closeChoose" card_style="width: 620px">
    <q-card class="bg-white">
      <n-header icon="qr_code_scanner">{{ $t('SelectProducts') }} — {{ lastCode }}</n-header>
      <q-separator />
      <q-card-section class="q-pa-none">
        <q-list separator>
          <q-item v-for="p in matches" :key="p.id" clickable @click="pick(p)">
            <q-item-section avatar>
              <div class="scn-thumb">
                <img v-if="p.image_url" :src="assetUrl(p.image_url)" :alt="p.name">
                <q-icon v-else name="inventory_2" size="16px" color="blue-grey-4" />
              </div>
            </q-item-section>
            <q-item-section>
              <q-item-label class="text-weight-bold">{{ p.name }}</q-item-label>
              <q-item-label caption>
                {{ p.sku || '—' }}
                <template v-if="p.barcode"> · {{ p.barcode }}</template>
                <template v-if="p.category?.name"> · {{ p.category.name }}</template>
              </q-item-label>
            </q-item-section>
            <q-item-section side>
              <div class="text-weight-bold">{{ money(p.sale_price) }}</div>
              <div class="text-caption text-grey-6">{{ Number(p.stock_qty ?? 0) }} {{ $t('InStockShort') }}</div>
            </q-item-section>
          </q-item>
        </q-list>
      </q-card-section>
      <q-separator />
      <q-card-actions align="right" class="q-px-md q-pb-md">
        <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" @click="closeChoose" />
      </q-card-actions>
    </q-card>
  </m-modal>

  <!-- Nothing matched: say so plainly, and offer the ways out. -->
  <m-modal :showCM="missOpen" @update:showCM="closeMiss" card_style="width: 440px">
    <q-card class="bg-white">
      <n-header icon="search_off">{{ $t('NoItemsFound') }}</n-header>
      <q-separator />
      <q-card-section class="text-center q-py-lg">
        <q-icon name="search_off" size="46px" color="grey-4" />
        <div class="text-weight-bold q-mt-sm">{{ $t('ProductNotFound') }}</div>
        <div class="scn-miss__code">{{ lastCode }}</div>
      </q-card-section>
      <q-separator />
      <q-card-actions align="right" class="q-px-md q-pb-md">
        <q-btn flat no-caps color="grey-7" :label="$t('RetryScan')" @click="closeMiss" />
        <q-btn v-if="$can('product-create')" unelevated no-caps color="primary" icon="add"
          :label="$t('AddProduct')" @click="createProduct" />
      </q-card-actions>
    </q-card>
  </m-modal>
</template>

<script setup>
import { ref, getCurrentInstance } from 'vue'
import { useRouter } from 'vue-router'
import { Notify } from 'quasar'
import { api, assetUrl } from '@/boot/axios'
import { useBarcodeScanner, scannerBeep } from '@/composables/useBarcodeScanner'

const props = defineProps({
  /**
   * What a successful scan means here:
   *   'search' — hand the product back so the page can filter/highlight it
   *   'cart'   — the POS: add straight to the basket
   * Either way the page receives @found; the mode only changes the wording
   * and whether a single hit is acted on automatically.
   */
  mode: { type: String, default: 'search' },
  /** Include draft/archived products (Purchase Orders need this). */
  includeInactive: { type: Boolean, default: false },
  /** Announce each hit. Off for the POS, which shows its own cart feedback. */
  notify: { type: Boolean, default: true },
  sound: { type: Boolean, default: true },
  enabled: { type: Boolean, default: true },
})

const emit = defineEmits(['found', 'missed', 'ambiguous'])

const { proxy } = getCurrentInstance()
const router = useRouter()
const t = (k) => proxy.$t(k)

const busy = ref(false)
const matches = ref([])
const chooseOpen = ref(false)
const missOpen = ref(false)
const lastCode = ref('')
const lastLabel = ref('')

const money = (v) => Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }) + ' AFN'

const manualOpen = ref(false)

async function lookup (code) {
  if (!code || busy.value) return
  lastCode.value = code
  busy.value = true
  try {
    const { data } = await api.get('/products/lookup', {
      params: { code, all: props.includeInactive ? 1 : 0 },
    })

    if (data.status === 'one') {
      accept(data.products[0], data.fuzzy)
      return
    }

    if (data.status === 'many') {
      matches.value = data.products
      chooseOpen.value = true
      if (props.sound) scannerBeep('many')
      emit('ambiguous', data.products, code)
      return
    }

    // none
    if (props.sound) scannerBeep('error')
    lastLabel.value = ''
    missOpen.value = true
    emit('missed', code)
  } catch (e) {
    if (props.sound) scannerBeep('error')
    Notify.create({ type: 'negative', position: 'top', message: e?.response?.data?.message || t('SaveFailed') })
  } finally {
    busy.value = false
  }
}

function accept (product, fuzzy = false) {
  if (props.sound) scannerBeep('ok')
  lastLabel.value = product.name
  if (props.notify) {
    Notify.create({
      type: 'positive',
      position: 'top',
      timeout: 1200,
      icon: props.mode === 'cart' ? 'add_shopping_cart' : 'qr_code_scanner',
      message: props.mode === 'cart'
        ? `${product.name} — ${t('AddedToCart')}`
        : `${product.name}${fuzzy ? '' : ''}`,
    })
  }
  emit('found', product, { code: lastCode.value, fuzzy })
}

function pick (p) {
  chooseOpen.value = false
  matches.value = []
  accept(p)
}

function closeChoose () { chooseOpen.value = false; matches.value = [] }
function closeMiss () { missOpen.value = false }

function createProduct () {
  missOpen.value = false
  // Carry the unknown code through so the new product is born with its barcode.
  router.push({ path: '/products', query: { new_barcode: lastCode.value } })
}

// The engine. Detection is global — no field needs focus for this to fire.
useBarcodeScanner({ onScan: lookup, enabled: () => props.enabled })

// Pages that keep their own search box can push a code in manually.
defineExpose({ lookup })
</script>

<style scoped>
.scn { display: inline-flex; align-items: center; gap: 8px; }
.scn__chip {
  display: inline-flex; align-items: center; gap: 6px;
  border: 1.5px solid #BBF7D0; background: #F0FDF4; color: #15803D;
  border-radius: 999px; padding: 4px 11px; cursor: pointer;
  font-size: 11.5px; font-weight: 700;
  transition: background .15s ease, border-color .15s ease;
}
.scn__chip:hover { background: #DCFCE7; border-color: #86EFAC; }
.scn--busy .scn__chip { background: #FEF3C7; border-color: #FDE68A; color: #B45309; }
.scn__dot {
  width: 7px; height: 7px; border-radius: 50%; background: #22C55E;
  animation: scnpulse 1.9s infinite;
}
.scn--busy .scn__dot { background: #D97706; }
@keyframes scnpulse {
  0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.55); }
  70% { box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
  100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}
.scn__last {
  font-size: 11px; color: #64748B; max-width: 220px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.scn-thumb {
  width: 38px; height: 38px; border-radius: 9px; overflow: hidden;
  border: 1px solid #E7ECF3; background: #F1F5F9;
  display: flex; align-items: center; justify-content: center;
}
.scn-thumb img { width: 100%; height: 100%; object-fit: cover; }
.scn-miss__code {
  margin-top: 6px; font-family: monospace; font-size: 13px;
  color: #B91C1C; background: #FEF2F2; border-radius: 8px;
  display: inline-block; padding: 3px 10px;
}
</style>
