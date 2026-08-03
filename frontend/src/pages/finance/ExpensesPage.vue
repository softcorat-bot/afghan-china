<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="payments" controlRoomButton="false" class="q-mt-xs">{{ $t('Expenses') }}</m-header>
        </div>

        <div class="col-6 col-md-3">
          <stat-card dense icon="payments" :label="$t('TotalExpenses')" :value="fmt(total)" color="#EA580C" tint="#FFEDD5" :sub="$t('CurrentList')" sub-icon="filter_list" />
        </div>
        <div class="col-6 col-md-3">
          <stat-card dense icon="calendar_month" :label="$t('ThisMonth')" :value="fmt(monthTotal)" color="#175A8C" tint="#E0EDF7" />
        </div>
        <div class="col-6 col-md-3">
          <stat-card dense icon="receipt_long" :label="$t('Records')" :value="rows.length" color="#7C3AED" tint="#EDE9FE" />
        </div>
        <div class="col-6 col-md-3">
          <stat-card dense icon="category" :label="$t('Categories')" :value="usedCategories" color="#0D9488" tint="#CCFBF1" />
        </div>

        <!-- filters -->
        <div class="col-12 col-md-4">
          <q-input outlined dense color="primary" v-model="filters.search" debounce="300"
            :label="$t('Search')" clearable @update:model-value="load">
            <template #prepend><q-icon name="search" color="primary" /></template>
          </q-input>
        </div>
        <div class="col-6 col-md-3">
          <q-select outlined dense color="primary" v-model="filters.category" :options="categoryOptions"
            emit-value map-options :label="$t('Category')" clearable @update:model-value="load">
            <template #prepend><q-icon name="category" color="primary" /></template>
          </q-select>
        </div>
        <div class="col-6 col-md-2">
          <q-input outlined dense color="primary" type="date" v-model="filters.from" :label="$t('From')" @update:model-value="load" />
        </div>
        <div class="col-6 col-md-2">
          <q-input outlined dense color="primary" type="date" v-model="filters.to" :label="$t('To')" @update:model-value="load" />
        </div>

        <action-bar :rows="rows" :columns="columns" filename="expenses"
          create-perm="expense-create" :add-label="$t('AddExpense')" @add="openCreate" />

        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            :can_edit="'expense-edit'" :can_delete="'expense-delete'" no-info
            @edit="openEdit" @del="remove">
            <template v-slot:body-cell-spent_on="props">
              <q-td :props="props">{{ $fmtDate(props.row.spent_on) }}</q-td>
            </template>
            <template v-slot:body-cell-category="props">
              <q-td :props="props">
                <q-chip dense size="sm" color="orange-1" text-color="deep-orange-9">
                  <q-icon :name="ICONS[props.row.category] || 'receipt'" size="13px" class="q-mr-xs" />
                  {{ $t(catKey(props.row.category)) }}
                </q-chip>
              </q-td>
            </template>
            <template v-slot:body-cell-amount="props">
              <q-td :props="props" class="text-weight-bold text-negative">{{ fmt(props.row.amount) }}</q-td>
            </template>
            <template v-slot:body-cell-method="props">
              <q-td :props="props">{{ $t(methodKey(props.row.method)) }}</q-td>
            </template>
            <template v-slot:body-cell-user="props">
              <q-td :props="props">{{ props.row.user?.name || '—' }}</q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>

    <!-- add / edit -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 560px">
      <q-card class="bg-white">
        <n-header icon="payments">{{ form.id ? $t('Edit') : $t('AddExpense') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 col-sm-6">
              <q-input outlined dense type="date" v-model="form.spent_on" :label="$t('SpentOn')"
                :rules="[v => !!v || $t('FieldIsRequired')]" />
            </div>
            <div class="col-12 col-sm-6">
              <q-select outlined dense v-model="form.category" :options="categoryOptions" emit-value map-options
                :label="$t('Category')" :rules="[v => !!v || $t('FieldIsRequired')]" />
            </div>
            <div class="col-12 col-sm-7">
              <q-input outlined dense v-model="form.payee" :label="$t('Payee')" />
            </div>
            <div class="col-12 col-sm-5">
              <q-input outlined dense type="number" step="0.01" min="0" v-model.number="form.amount"
                :label="$t('Amount')" suffix="AFN" :rules="[v => Number(v) > 0 || $t('FieldIsRequired')]" />
            </div>
            <div class="col-12 col-sm-6">
              <q-select outlined dense v-model="form.method" :options="methodOptions" emit-value map-options :label="$t('Method')" />
            </div>
            <div class="col-12 col-sm-6">
              <q-input outlined dense v-model="form.reference" :label="$t('Reference')" />
            </div>
            <div class="col-12">
              <q-input outlined dense type="textarea" autogrow v-model="form.note" :label="$t('Notes')" />
            </div>
          </q-card-section>
          <q-separator />
          <q-card-actions align="right" class="q-px-md q-pb-md">
            <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
            <q-btn unelevated no-caps color="primary" icon="check" type="submit" :label="$t('Save')" :loading="saving" />
          </q-card-actions>
        </q-form>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
/**
 * Shop running costs. Money that leaves the business without becoming stock,
 * so the owner can see it beside revenue instead of only seeing what went on
 * inventory.
 */
import { ref, reactive, computed, onMounted, getCurrentInstance } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()
const fmt = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })

const ICONS = {
  rent: 'home_work', electricity: 'bolt', water: 'water_drop', internet: 'wifi',
  transport: 'local_shipping', wages: 'groups', repairs: 'build',
  cleaning: 'cleaning_services', marketing: 'campaign', government: 'account_balance',
  other: 'receipt',
}
const catKey = (c) => 'Exp' + String(c || 'other').charAt(0).toUpperCase() + String(c || 'other').slice(1)
const methodKey = (m) => ({ cash: 'Cash', bank: 'Bank', mobile: 'Mobile' })[m] || m

const loading = ref(false)
const saving = ref(false)
const rows = ref([])
const total = ref(0)
const categories = ref([])
const filter = ref('')
const dialog = ref(false)
const filters = reactive({ search: '', category: null, from: '', to: '' })

const categoryOptions = computed(() =>
  categories.value.map(c => ({ label: proxy.$t(catKey(c)), value: c })))
const methodOptions = computed(() => ['cash', 'bank', 'mobile']
  .map(m => ({ label: proxy.$t(methodKey(m)), value: m })))

const monthTotal = computed(() => {
  const p = new Date().toISOString().slice(0, 7)
  return rows.value.filter(r => String(r.spent_on).startsWith(p))
    .reduce((a, r) => a + Number(r.amount), 0)
})
const usedCategories = computed(() => new Set(rows.value.map(r => r.category)).size)

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'spent_on', label: 'SpentOn', field: 'spent_on', align: 'left', sortable: true },
  { name: 'category', label: 'Category', field: 'category', align: 'left', sortable: true },
  { name: 'payee', label: 'Payee', field: 'payee', align: 'left' },
  { name: 'amount', label: 'Amount', field: 'amount', align: 'right', sortable: true },
  { name: 'method', label: 'Method', field: 'method', align: 'left' },
  { name: 'reference', label: 'Reference', field: 'reference', align: 'left' },
  { name: 'user', label: 'CreatedBy', field: 'user', align: 'left' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' },
]

const blank = () => ({
  id: null,
  spent_on: new Date().toISOString().slice(0, 10),
  category: 'other', payee: '', amount: null, method: 'cash', reference: '', note: '',
})
const form = reactive(blank())

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/expenses', { params: { ...filters } })
    rows.value = data.data || []
    total.value = data.total || 0
    categories.value = data.categories || []
  } catch (_) { rows.value = [] } finally { loading.value = false }
}

function openCreate () { Object.assign(form, blank()); dialog.value = true }
function openEdit (id) {
  const row = rows.value.find(r => r.id === id)
  if (!row) return
  Object.assign(form, {
    id: row.id,
    spent_on: String(row.spent_on).slice(0, 10),
    category: row.category, payee: row.payee || '', amount: Number(row.amount),
    method: row.method, reference: row.reference || '', note: row.note || '',
  })
  dialog.value = true
}

async function save () {
  saving.value = true
  try {
    const payload = {
      spent_on: form.spent_on, category: form.category, payee: form.payee || null,
      amount: Number(form.amount), method: form.method,
      reference: form.reference || null, note: form.note || null,
    }
    if (form.id) await api.put('/expenses/' + form.id, payload)
    else await api.post('/expenses', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: proxy.$t('Saved') })
    dialog.value = false
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

function remove (id) { proxy.$delete('expenses/' + id, load) }

onMounted(load)
</script>
