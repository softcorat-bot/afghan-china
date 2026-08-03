<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="inventory_2" controlRoomButton="false" class="q-mt-xs">{{ $t('Products') }}</m-header>
        </div>

        <!-- Scan anywhere on this page: one hit opens the product, several
             filter the table down to them. -->
        <div class="col-12 q-mt-xs">
          <product-scanner mode="search" include-inactive :notify="false"
            @found="onScanFound" @ambiguous="onScanAmbiguous" />
        </div>

        <!-- Summary stat cards -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-md">
            <div class="col-6 col-sm-3"><stat-card dense icon="inventory_2" :label="$t('TotalProducts')" :value="rows.length" color="#175A8C" tint="#E0EDF7" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="check_circle" :label="$t('ActiveProducts')" :value="activeCount" color="#0D9488" tint="#CCFBF1" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="warning" :label="$t('LowStock')" :value="lowStockCount" color="#EA580C" tint="#FFEDD5" /></div>
            <div class="col-6 col-sm-3"><stat-card dense icon="savings" :label="$t('TotalStockValue')" :value="fmt(stockValue)" color="#7C3AED" tint="#EDE9FE" /></div>
          </div>
        </div>

        <!-- Filters -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-sm items-center q-pa-sm bg-blue-grey-1 my_radio_less" style="border-radius:10px">
            <div class="col-12 col-sm-4">
              <q-input outlined dense color="primary" v-model="filters.search" :label="$t('Search')" clearable debounce="350" @update:model-value="load">
                <template #prepend><q-icon name="search" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-3">
              <q-select outlined dense color="primary" v-model="filters.category_id" :options="categoryOptions" :label="$t('Category')" emit-value map-options clearable @update:model-value="load">
                <template #prepend><q-icon name="category" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-sm-3">
              <q-select outlined dense color="primary" v-model="filters.status" :options="statusOptions" :label="$t('Status')" emit-value map-options clearable @update:model-value="load">
                <template #prepend><q-icon name="flag" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-12 col-sm-2 flex items-center">
              <q-toggle v-model="filters.low_stock" :label="$t('LowStockOnly')" color="deep-orange" @update:model-value="load" />
            </div>
          </div>
        </div>

        <action-bar :rows="rows" :columns="columns" filename="products" create-perm="product-create" @add="openCreate" @update:filtered="filteredRows = $event" />

        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            :can_edit="'product-edit'" :can_delete="'product-delete'" :can_show="'product-show'"
            info-icon="dashboard" info-route="/products" @edit="openEdit" @del="remove">

            <template v-slot:body-cell-image="props">
              <q-td :props="props">
                <div class="row-img" :class="{ 'row-img--busy': rowUploadingId === props.row.id }" @click.stop="pickRowImage(props.row)">
                  <img v-if="props.row.image_url" :src="assetUrl(props.row.image_url)" :alt="props.row.name" loading="lazy">
                  <q-icon v-else name="add_a_photo" size="18px" color="blue-grey-4" />
                  <div class="row-img__hover"><q-icon name="photo_camera" size="13px" color="white" /></div>
                  <q-spinner v-if="rowUploadingId === props.row.id" class="row-img__spin" color="primary" size="18px" />
                  <q-tooltip>{{ $t('ClickToChangePhoto') }}</q-tooltip>
                </div>
              </q-td>
            </template>

            <template v-slot:body-cell-name="props">
              <q-td :props="props">
                <div class="text-weight-medium prod-link" @click.stop="$router.push('/products/' + props.row.id)">{{ props.row.name }}</div>
                <div v-if="props.row.name_fa" class="text-caption text-grey-6">{{ props.row.name_fa }}</div>
              </q-td>
            </template>

            <template v-slot:body-cell-sku="props">
              <q-td :props="props">
                <div>{{ props.row.sku || '—' }}</div>
                <div v-if="props.row.barcode" class="text-caption text-grey-6"><q-icon name="qr_code_2" size="12px" /> {{ props.row.barcode }}</div>
              </q-td>
            </template>

            <template v-slot:body-cell-category="props">
              <q-td :props="props">{{ props.row.category?.name || '—' }}</q-td>
            </template>

            <template v-slot:body-cell-sale_price="props">
              <q-td :props="props" class="text-weight-bold">{{ fmt(props.row.sale_price) }}</q-td>
            </template>

            <template v-slot:body-cell-stock_qty="props">
              <q-td :props="props">
                <q-badge :color="props.row.low_stock ? 'red' : 'green-7'">{{ props.row.stock_qty ?? 0 }}</q-badge>
              </q-td>
            </template>

            <template v-slot:body-cell-status="props">
              <q-td :props="props">
                <q-chip dense size="sm" :color="statusColor(props.row.status)" text-color="white">{{ $t(statusKey(props.row.status)) }}</q-chip>
              </q-td>
            </template>

            <template v-slot:body-cell-margin="props">
              <q-td :props="props">{{ props.row.margin != null ? Number(props.row.margin).toFixed(1) + '%' : '—' }}</q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>

    <!-- Add / edit product (modal) -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 720px">
      <q-card class="bg-white">
        <n-header icon="inventory_2">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('Product') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 flex flex-center q-mb-xs">
              <div class="prod-img" @click="pickImage()">
                <img v-if="form.image_url" :src="assetUrl(form.image_url)" :alt="form.name">
                <img v-else-if="pendingPreview" :src="pendingPreview" :alt="form.name">
                <img v-else-if="pendingLibraryUrl" :src="pendingLibraryUrl" :alt="form.name">
                <div v-else class="prod-img__ph"><q-icon name="add_a_photo" size="26px" color="grey-5" /></div>
                <div v-if="imgUploading" class="prod-img__busy"><q-spinner color="white" size="22px" /></div>
                <div class="prod-img__edit"><q-icon name="photo_camera" size="14px" color="white" /></div>
              </div>
            </div>

            <div class="col-12 col-sm-6"><n-name :name="form.name" @update:name="form.name = $event" icon="inventory_2" :label="$t('Name')" autofocus /></div>
            <div class="col-12 col-sm-6"><n-name :name="form.name_fa" @update:name="form.name_fa = $event" icon="translate" :label="$t('NameFa')" :rules="[]" /></div>

            <div class="col-12 col-sm-6"><n-name :name="form.sku" @update:name="form.sku = $event" icon="tag" :label="$t('Sku')" :rules="[]" /></div>
            <div class="col-12 col-sm-6">
              <q-input outlined dense color="primary" class="q-mt-sm" v-model="form.barcode" :label="$t('Barcode')" :hint="$t('ScanHint')">
                <template #prepend><q-icon name="qr_code_scanner" color="primary" /></template>
              </q-input>
            </div>

            <!-- A carton usually carries more than one code: the maker's EAN,
                 the supplier's label, and the shelf tag the shop prints. Any of
                 them must find this product when scanned. -->
            <div class="col-12 col-sm-6">
              <q-input outlined dense color="primary" class="q-mt-sm" v-model="form.barcode2" :label="$t('SecondaryBarcode')">
                <template #prepend><q-icon name="qr_code_2" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-6">
              <q-input outlined dense color="primary" class="q-mt-sm" v-model="form.internal_code" :label="$t('InternalCode')">
                <template #prepend><q-icon name="pin" color="primary" /></template>
              </q-input>
            </div>

            <div class="col-12 col-sm-6">
              <q-select outlined dense color="primary" class="q-mt-sm" v-model="form.category_id" :options="categoryOptions" :label="$t('Category')" emit-value map-options clearable>
                <template #prepend><q-icon name="category" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-12 col-sm-3"><n-name :name="form.brand" @update:name="form.brand = $event" icon="sell" :label="$t('Brand')" :rules="[]" /></div>
            <div class="col-12 col-sm-3">
              <q-select outlined dense color="primary" class="q-mt-sm" v-model="form.unit" :options="unitOptions" :label="$t('Unit')">
                <template #prepend><q-icon name="straighten" color="primary" /></template>
              </q-select>
            </div>

            <div class="col-12 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" v-model.number="form.cost_price" :label="$t('CostPrice')" suffix="AFN">
                <template #prepend><q-icon name="payments" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" v-model.number="form.sale_price" :label="$t('SalePrice')" suffix="AFN"
                :rules="[val => (val !== null && val !== '' && Number(val) >= 0) || $t('FieldIsRequired')]">
                <template #prepend><q-icon name="sell" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" v-model.number="form.compare_at_price" :label="$t('CompareAtPrice')" suffix="AFN">
                <template #prepend><q-icon name="price_check" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" v-model.number="form.wholesale_price" :label="$t('WholesalePrice')" suffix="AFN" :hint="$t('WholesalePriceHint')">
                <template #prepend><q-icon name="business_center" color="primary" /></template>
              </q-input>
            </div>

            <div class="col-12 col-sm-4">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" step="0.01" v-model.number="form.tax_rate" :label="$t('TaxRate')" suffix="%">
                <template #prepend><q-icon name="percent" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-4 flex items-center">
              <q-toggle v-model="form.track_inventory" :label="$t('TrackInventory')" color="primary" />
            </div>
            <div class="col-6 col-sm-2">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" v-model.number="form.stock_qty" :label="$t('StockQty')" :disable="!form.track_inventory">
                <template #prepend><q-icon name="inventory" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-6 col-sm-2">
              <q-input outlined dense color="primary" class="q-mt-sm" type="number" v-model.number="form.min_stock" :label="$t('MinStock')">
                <template #prepend><q-icon name="production_quantity_limits" color="primary" /></template>
              </q-input>
            </div>

            <div class="col-12 col-sm-6">
              <q-select outlined dense color="primary" class="q-mt-sm" v-model="form.status" :options="statusOptions" :label="$t('Status')" emit-value map-options>
                <template #prepend><q-icon name="flag" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-12 col-sm-6">
              <q-select outlined dense color="primary" class="q-mt-sm" v-model="form.tags" :label="$t('Tags')" multiple use-chips use-input new-value-mode="add-unique" hide-dropdown-icon>
                <template #prepend><q-icon name="label" color="primary" /></template>
              </q-select>
            </div>

            <div class="col-12">
              <q-input outlined dense color="primary" type="textarea" autogrow v-model="form.description" :label="$t('Description')">
                <template #prepend><q-icon name="notes" color="primary" /></template>
              </q-input>
            </div>
          </q-card-section>
          <q-separator />
          <n-submit :submitting="saving" :label="$t('Save')" />
        </q-form>
      </q-card>
    </m-modal>
    <input ref="imgInput" type="file" accept="image/*" class="hidden" @change="onImage">

    <!-- ── photo chooser: upload a new one, or reuse one already here ── -->
    <m-modal :showCM="pickerOpen" @update:showCM="pickerOpen = $event" card_style="width: 720px">
      <q-card class="bg-white">
        <n-header icon="photo_library">{{ $t('ProductPhoto') }}</n-header>
        <q-separator />
        <q-tabs v-model="pickerTab" dense no-caps align="left" class="pk-tabs"
          active-color="primary" indicator-color="primary" narrow-indicator>
          <q-tab name="library" icon="photo_library" :label="$t('FromLibrary')" />
          <q-tab name="upload" icon="upload" :label="$t('UploadNew')" />
        </q-tabs>
        <q-separator />

        <q-tab-panels v-model="pickerTab" animated>
          <!-- pick one that is already here -->
          <q-tab-panel name="library" class="q-pa-md">
            <div v-if="libLoading" class="pk-state"><q-spinner size="34px" color="primary" /></div>
            <div v-else-if="!library.length" class="pk-state">
              <q-icon name="photo_library" size="38px" color="grey-4" />
              <div>{{ $t('LibraryEmpty') }}</div>
            </div>
            <template v-else>
              <div class="text-caption text-grey-6 q-mb-sm">{{ $t('LibraryHint') }}</div>
              <div class="pk-grid">
                <button v-for="it in library" :key="it.key" class="pk-item"
                  :class="{ 'pk-item--busy': libApplying === it.key }"
                  @click="useFromLibrary(it)">
                  <img :src="assetUrl(it.url)" :alt="it.name" loading="lazy">
                  <span class="pk-item__name">{{ it.name }}</span>
                  <q-badge v-if="it.source === 'seed'" class="pk-item__tag" color="blue-grey-2" text-color="blue-grey-9">
                    {{ $t('Included') }}
                  </q-badge>
                  <div v-if="libApplying === it.key" class="pk-item__spin"><q-spinner color="white" size="22px" /></div>
                </button>
              </div>
            </template>
          </q-tab-panel>

          <!-- or bring a new file -->
          <q-tab-panel name="upload" class="q-pa-md">
            <div class="pk-drop" @click="chooseUpload">
              <q-icon name="cloud_upload" size="40px" color="primary" />
              <div class="pk-drop__t">{{ $t('ChooseFile') }}</div>
              <div class="pk-drop__s">{{ $t('UploadShrinkHint') }}</div>
            </div>
          </q-tab-panel>
        </q-tab-panels>

        <q-separator />
        <q-card-actions align="right" class="q-px-md q-pb-md">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup @click="pickerOpen = false" />
        </q-card-actions>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Notify } from 'quasar'
import { api, assetUrl } from '@/boot/axios'

const route = useRoute()
const router = useRouter()

const { proxy } = getCurrentInstance()

const rows = ref([])
const filteredRows = ref([])
const loading = ref(false)
const saving = ref(false)
const filter = ref('')
const dialog = ref(false)
const categoryOptions = ref([])

const filters = reactive({ search: '', category_id: null, status: null, low_stock: false })

const statusOptions = [
  { label: 'Active', value: 'active' },
  { label: 'Draft', value: 'draft' },
  { label: 'Archived', value: 'archived' }
]
const unitOptions = ['pcs', 'kg', 'box', 'litre', 'm', 'pack']

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'image', label: 'Image', field: 'id', align: 'left' },
  { name: 'name', label: 'Name', field: 'name', align: 'left', sortable: true },
  { name: 'sku', label: 'Sku', field: 'sku', align: 'left', sortable: true },
  { name: 'category', label: 'Category', field: row => row.category?.name, align: 'left' },
  { name: 'sale_price', label: 'SalePrice', field: 'sale_price', align: 'left', sortable: true },
  { name: 'stock_qty', label: 'StockQty', field: 'stock_qty', align: 'left', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
  { name: 'margin', label: 'Margin', field: 'margin', align: 'left', sortable: true },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' }
]

const activeCount = computed(() => rows.value.filter(r => r.status === 'active').length)
const lowStockCount = computed(() => rows.value.filter(r => r.low_stock).length)
const stockValue = computed(() => rows.value.reduce((s, r) => s + (Number(r.cost_price) || 0) * (Number(r.stock_qty) || 0), 0))

function fmt (v) { return (Number(v) || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' AFN' }
function statusColor (s) { return s === 'active' ? 'green-7' : s === 'archived' ? 'orange-8' : 'grey-6' }
function statusKey (s) { return s === 'active' ? 'Active' : s === 'archived' ? 'Archived' : 'Draft' }

const blank = () => ({
  id: null, name: '', name_fa: '', sku: '', barcode: '', barcode2: '', internal_code: '', category_id: null, brand: '', unit: 'pcs',
  description: '', status: 'active', cost_price: 0, sale_price: 0, wholesale_price: null, compare_at_price: null, tax_rate: 0,
  track_inventory: true, stock_qty: 0, min_stock: 0, tags: [], image_url: null
})
const form = reactive(blank())

// ── product photo (public-storage image) ──
const imgInput = ref(null)
const imgUploading = ref(false)
const rowUploadingId = ref(null)
let uploadTargetId = null // form.id when picked from the modal; row id when picked from the table

// When adding a new product the file is held locally and uploaded right after create
const pendingFile = ref(null)
const pendingPreview = ref(null)

function clearPending () {
  if (pendingPreview.value) URL.revokeObjectURL(pendingPreview.value)
  pendingFile.value = null
  pendingPreview.value = null
  pendingLibraryKey.value = null
  pendingLibraryUrl.value = null
}

/**
 * Clicking a photo opens a chooser instead of jumping straight to the file
 * dialog: most of the time the picture is already in the shop's library (a
 * previous upload, or one that shipped with the app), and hunting for the file
 * again is the slow path.
 */
function pickImage () { openPicker(form.id) }
function pickRowImage (row) { openPicker(row.id) }

const pickerOpen = ref(false)
const pickerTab = ref('library')
const library = ref([])
const libLoading = ref(false)
const libApplying = ref(null)
// A new product has no id yet, so a library pick waits for save() like a file.
const pendingLibraryKey = ref(null)
const pendingLibraryUrl = ref(null)

function openPicker (id) {
  uploadTargetId = id ?? null
  pickerOpen.value = true
  if (!library.value.length) loadLibrary()
}

async function loadLibrary () {
  libLoading.value = true
  try {
    const { data } = await api.get('/products/image-library')
    library.value = data || []
  } catch (_) { library.value = [] } finally { libLoading.value = false }
}

function chooseUpload () {
  pickerOpen.value = false
  // uploadTargetId is already set; onImage picks it up.
  setTimeout(() => imgInput.value?.click(), 60)
}

async function useFromLibrary (item) {
  const id = uploadTargetId
  if (!id) {
    // New product: remember the choice and show it as the preview.
    clearPending()
    pendingLibraryKey.value = item.key
    pendingLibraryUrl.value = assetUrl(item.url)
    pickerOpen.value = false
    return
  }
  libApplying.value = item.key
  try {
    const { data } = await api.post(`/products/${id}/image-from-library`, { key: item.key })
    if (form.id === id) form.image_url = data.image_url
    const row = rows.value.find(r => r.id === id); if (row) row.image_url = data.image_url
    pickerOpen.value = false
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: proxy.$t('PhotoUpdated') })
    // The new file joins the library for the next product.
    loadLibrary()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Could not set that picture' })
  } finally { libApplying.value = null }
}

async function onImage (e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  const id = uploadTargetId
  uploadTargetId = null
  if (!file) return
  if (!id) { // new product: keep the file until save() creates the record
    clearPending()
    pendingFile.value = file
    pendingPreview.value = URL.createObjectURL(file)
    return
  }
  const fromForm = form.id === id
  if (fromForm) imgUploading.value = true
  else rowUploadingId.value = id
  try {
    // The file the user picked, unmodified — no browser-side re-encoding.
    const fd = new FormData()
    fd.append('image', file)
    const { data } = await api.post(`/products/${id}/image`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    if (fromForm) form.image_url = data.image_url
    const row = rows.value.find(r => r.id === id); if (row) row.image_url = data.image_url
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Photo updated' })
  } catch (err) {
    Notify.create({ type: 'negative', message: err?.response?.data?.message || 'Upload failed' })
  } finally { imgUploading.value = false; rowUploadingId.value = null }
}

async function load () {
  loading.value = true
  try {
    const params = {}
    if (filters.search) params.search = filters.search
    if (filters.category_id) params.category_id = filters.category_id
    if (filters.status) params.status = filters.status
    if (filters.low_stock) params.low_stock = 1
    const { data } = await api.get('/products', { params })
    rows.value = data
  } finally { loading.value = false }
}

async function loadMeta () {
  try {
    const { data } = await api.get('/product-categories')
    categoryOptions.value = (data || []).map(c => ({ label: c.name, value: c.id }))
  } catch (_) {}
}

function openCreate () { Object.assign(form, blank()); clearPending(); dialog.value = true }
function openEdit (id) {
  const r = rows.value.find(x => x.id === id); if (!r) return
  clearPending()
  Object.assign(form, {
    id: r.id, name: r.name || '', name_fa: r.name_fa || '', sku: r.sku || '', barcode: r.barcode || '',
    barcode2: r.barcode2 || '', internal_code: r.internal_code || '',
    category_id: r.category_id ?? r.category?.id ?? null, brand: r.brand || '', unit: r.unit || 'pcs',
    description: r.description || '', status: r.status || 'active',
    cost_price: Number(r.cost_price) || 0, sale_price: Number(r.sale_price) || 0,
    wholesale_price: r.wholesale_price != null ? Number(r.wholesale_price) : null,
    compare_at_price: r.compare_at_price != null ? Number(r.compare_at_price) : null,
    tax_rate: Number(r.tax_rate) || 0, track_inventory: !!r.track_inventory,
    stock_qty: Number(r.stock_qty) || 0, min_stock: Number(r.min_stock) || 0,
    tags: Array.isArray(r.tags) ? r.tags : [], image_url: r.image_url || null
  })
  dialog.value = true
}

async function save () {
  saving.value = true
  try {
    const payload = {
      name: form.name, name_fa: form.name_fa, sku: form.sku, barcode: form.barcode,
      barcode2: form.barcode2, internal_code: form.internal_code,
      category_id: form.category_id, brand: form.brand, unit: form.unit, description: form.description,
      status: form.status, cost_price: form.cost_price, sale_price: form.sale_price,
      wholesale_price: form.wholesale_price,
      compare_at_price: form.compare_at_price, tax_rate: form.tax_rate,
      track_inventory: form.track_inventory, stock_qty: form.track_inventory ? form.stock_qty : 0,
      min_stock: form.min_stock, tags: form.tags
    }
    if (form.id) {
      await api.put('/products/' + form.id, payload)
    } else {
      const { data: created } = await api.post('/products', payload)
      if (pendingFile.value && created?.id) {
        try {
          const fd = new FormData()
          fd.append('image', pendingFile.value)
          await api.post(`/products/${created.id}/image`, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
        } catch (_) {
          Notify.create({ type: 'warning', message: 'Product saved, but the photo upload failed' })
        }
      } else if (pendingLibraryKey.value && created?.id) {
        try {
          await api.post(`/products/${created.id}/image-from-library`, { key: pendingLibraryKey.value })
        } catch (_) {
          Notify.create({ type: 'warning', message: 'Product saved, but the picture could not be attached' })
        }
      }
    }
    clearPending()
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    dialog.value = false; load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

function remove (id) { proxy.$delete('products/' + id, load) }

/**
 * A scan on the catalog means "show me this product" — one match goes straight
 * to its dashboard, which is what someone holding the item actually wants.
 */
function onScanFound (p) {
  router.push('/products/' + p.id)
}

/** Several products share the code: leave the table filtered to them. */
function onScanAmbiguous (list, code) {
  filters.search = code
  load()
}

// A scan that found nothing offers "Add product"; it arrives back here with the
// code so the new product is created already wearing its barcode.
function openCreateWithBarcode (code) {
  openCreate()
  form.barcode = code
}

// The dashboard's Categories card links here with ?category_id=… — arrive
// filtered, so the click actually answers "which products are in this category".
onMounted(() => {
  const q = route.query.category_id
  if (q) filters.category_id = Number(q)
  load()
  loadMeta()

  // Arrived from a scan that matched nothing: open the form already carrying
  // the code, so the product is created wearing the barcode that was scanned.
  if (route.query.new_barcode) {
    openCreateWithBarcode(String(route.query.new_barcode))
    router.replace({ path: '/products' })
  }
})
</script>

<style scoped>
.prod-img {
  position: relative; width: 96px; height: 96px; border-radius: 14px;
  overflow: hidden; cursor: pointer; border: 1.5px solid #E7ECF3; background: #F1F5F9;
  display: flex; align-items: center; justify-content: center;
}
.prod-img img { width: 100%; height: 100%; object-fit: cover; }
.prod-img__ph { display: flex; align-items: center; justify-content: center; }
.prod-img__edit {
  position: absolute; bottom: 0; left: 0; right: 0; height: 24px;
  background: rgba(18, 58, 102, 0.75); display: flex; align-items: center; justify-content: center;
}
.prod-img__busy {
  position: absolute; inset: 0; background: rgba(0, 0, 0, 0.4);
  display: flex; align-items: center; justify-content: center;
}
</style>

<style scoped>
.row-img {
  position: relative; width: 40px; height: 40px; border-radius: 10px; overflow: hidden;
  border: 1.5px solid #E7ECF3; background: #F1F5F9; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.row-img img { width: 100%; height: 100%; object-fit: cover; }
.row-img__hover {
  position: absolute; inset: auto 0 0 0; height: 15px; background: rgba(18, 58, 102, 0.72);
  display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s ease;
}
.row-img:hover .row-img__hover { opacity: 1; }
.row-img__spin { position: absolute; inset: 0; margin: auto; background: rgba(255,255,255,.7); border-radius: 10px; padding: 2px; }
.prod-link { color: var(--q-primary); cursor: pointer; }
.prod-link:hover { text-decoration: underline; }

/* ── photo chooser ── */
.pk-tabs { color: #64748B; }
.pk-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 8px; padding: 44px 12px; color: #94A3B8; font-size: 13px;
}
.pk-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
  gap: 10px; max-height: 46vh; overflow-y: auto; padding: 2px;
}
.pk-item {
  position: relative;
  padding: 0; border: 1.5px solid #E7ECF3; border-radius: 12px;
  background: #F8FAFC; cursor: pointer; overflow: hidden;
  transition: border-color .15s ease, transform .14s ease, box-shadow .2s ease;
}
.pk-item:hover { border-color: var(--q-primary); transform: translateY(-2px); box-shadow: 0 10px 18px -12px rgba(18, 58, 102, .5); }
.pk-item img { display: block; width: 100%; aspect-ratio: 4/3; object-fit: cover; }
.pk-item__name {
  display: block; padding: 4px 6px;
  font-size: 9.5px; color: #64748B;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pk-item__tag { position: absolute; top: 5px; inset-inline-start: 5px; font-size: 8.5px; }
.pk-item__spin {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(15, 23, 42, .45);
}
.pk-drop {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 6px; padding: 40px 16px;
  border: 2px dashed #C9D8E8; border-radius: 14px;
  background: #F8FBFF; cursor: pointer;
  transition: border-color .15s ease, background .15s ease;
}
.pk-drop:hover { border-color: var(--q-primary); background: #F1F6FB; }
.pk-drop__t { font-size: 14px; font-weight: 800; color: #175A8C; }
.pk-drop__s { font-size: 11.5px; color: #94A3B8; }
</style>
