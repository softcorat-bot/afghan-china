<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="groups" controlRoomButton="false" class="q-mt-xs">{{ $t('Customers') }}</m-header>
        </div>

        <!-- Stat cards -->
        <div class="col-12 col-sm-4">
          <stat-card icon="groups" :label="$t('TotalCustomers')" :value="rows.length" color="#175A8C" tint="#E0EDF7" />
        </div>
        <div class="col-12 col-sm-4">
          <stat-card icon="payments" :label="$t('TotalSpent')" :value="fmt(totalSpent)" color="#1B7A5A" tint="#DDF3E9" />
        </div>
        <div class="col-12 col-sm-4">
          <stat-card icon="shopping_cart" :label="$t('TotalOrders')" :value="totalOrders" color="#8C5A17" tint="#F7EBDD" />
        </div>

        <!-- Create + search -->
        <div class="col-12 q-mt-xs row items-center justify-between">
          <progress-btn color="teal" icon="add" @click="openCreate">{{ $t('AddNew') }}</progress-btn>
          <q-input outlined dense color="primary" v-model="search" debounce="300" :placeholder="$t('Search')" style="min-width:240px" @update:model-value="load">
            <template #prepend><q-icon name="search" color="primary" /></template>
          </q-input>
        </div>

        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            :can_edit="true" :can_delete="true" :can_show="true" info-icon="dashboard"
            info-route="/customers" @edit="openEdit" @del="remove">
            <template v-slot:body-cell-orders_count="props">
              <q-td :props="props">
                <q-badge color="primary" :label="props.row.orders_count ?? 0" />
              </q-td>
            </template>
            <template v-slot:body-cell-total_spent="props">
              <q-td :props="props">
                {{ fmt(props.row.total_spent) }}
                <q-chip v-if="props.row.loyalty?.tier_label" dense size="sm" class="q-ml-xs cust-tier"
                  :style="{ '--tier': props.row.loyalty.tier_color }">
                  <q-icon name="workspace_premium" size="12px" class="q-mr-xs" />
                  {{ $t(props.row.loyalty.tier_label) }}
                </q-chip>
              </q-td>
            </template>
            <template v-slot:body-cell-active="props">
              <q-td :props="props">
                <q-chip dense size="sm" :color="props.row.active ? 'positive' : 'grey'" text-color="white">
                  {{ props.row.active ? $t('Active') : $t('Inactive') }}
                </q-chip>
              </q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>

    <!-- Add / edit customer (modal) -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 520px">
      <q-card class="bg-white">
        <n-header icon="person">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('Customer') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 col-sm-6"><n-name :name="form.name" @update:name="form.name = $event" icon="person" :label="$t('Name')" autofocus /></div>
            <div class="col-12 col-sm-6"><n-name :name="form.phone" @update:name="form.phone = $event" icon="phone" :label="$t('Phone')" :rules="[]" /></div>
            <div class="col-12"><n-name :name="form.email" @update:name="form.email = $event" icon="email" :label="$t('Email')" :rules="[]" /></div>
            <div class="col-12">
              <q-input outlined dense color="primary" type="textarea" autogrow v-model="form.address" :label="$t('Address')">
                <template #prepend><q-icon name="home" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12"><q-toggle v-model="form.active" :label="$t('Active')" color="primary" /></div>
          </q-card-section>
          <q-separator />
          <n-submit :submitting="saving" :label="$t('Save')" />
        </q-form>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()

const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const filter = ref('')
const search = ref('')
const dialog = ref(false)

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'name', label: 'Name', field: 'name', align: 'left', sortable: true },
  { name: 'phone', label: 'Phone', field: 'phone', align: 'left' },
  { name: 'email', label: 'Email', field: 'email', align: 'left' },
  { name: 'orders_count', label: 'Orders', field: 'orders_count', align: 'center', sortable: true },
  { name: 'total_spent', label: 'TotalSpent', field: 'total_spent', align: 'right', sortable: true },
  { name: 'loyalty_points', label: 'LoyaltyPoints', field: 'loyalty_points', align: 'right', sortable: true },
  { name: 'active', label: 'Status', field: 'active', align: 'center' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' }
]

const totalSpent = computed(() => rows.value.reduce((s, r) => s + Number(r.total_spent || 0), 0))
const totalOrders = computed(() => rows.value.reduce((s, r) => s + Number(r.orders_count || 0), 0))

function fmt (v) {
  return `${Number(v || 0).toLocaleString()} AFN`
}

const blank = () => ({ id: null, name: '', phone: '', email: '', address: '', active: true })
const form = reactive(blank())

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/customers', { params: { search: search.value } })
    rows.value = data
  } finally { loading.value = false }
}

function openCreate () { Object.assign(form, blank()); dialog.value = true }
function openEdit (id) {
  const r = rows.value.find(x => x.id === id); if (!r) return
  Object.assign(form, { id: r.id, name: r.name, phone: r.phone || '', email: r.email || '', address: r.address || '', active: !!r.active })
  dialog.value = true
}

async function save () {
  saving.value = true
  try {
    const payload = { name: form.name, phone: form.phone, email: form.email, address: form.address, active: form.active }
    if (form.id) await api.put('/customers/' + form.id, payload)
    else await api.post('/customers', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    dialog.value = false
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

function remove (id) { proxy.$delete('customers/' + id, load) }

onMounted(load)
</script>

<style scoped>
/* The loyalty badge is tinted by the tier it represents. */
.cust-tier {
  background: color-mix(in srgb, var(--tier) 12%, #fff);
  color: var(--tier);
  font-weight: 700;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tier) 35%, transparent);
}
</style>
