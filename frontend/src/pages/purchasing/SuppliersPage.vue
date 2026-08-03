<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="local_shipping" controlRoomButton="false" class="q-mt-xs">{{ $t('Suppliers') }}</m-header>
        </div>

        <!-- Stat cards -->
        <div class="col-12 col-sm-4">
          <stat-card icon="local_shipping" :label="$t('TotalSuppliers')" :value="rows.length" color="#175A8C" tint="#E0EDF7" />
        </div>
        <div class="col-12 col-sm-4">
          <stat-card icon="payments" :label="$t('TotalPayable')" :value="fmt(totalPayable)" color="#8C1717" tint="#F7DDDD" />
        </div>
        <div class="col-12 col-sm-4">
          <stat-card icon="check_circle" :label="$t('ActiveSuppliers')" :value="activeCount" color="#1B7A5A" tint="#DDF3E9" />
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
            :can_edit="true" :can_delete="true" :can_show="'supplier-list'"
            info-icon="dashboard" info-route="/suppliers"
            @edit="openEdit" @del="remove">
            <template v-slot:body-cell-balance="props">
              <q-td :props="props">
                <span :class="Number(props.row.balance || 0) > 0 ? 'text-negative text-weight-medium' : ''">{{ fmt(props.row.balance) }}</span>
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

    <!-- Add / edit supplier (modal) -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 520px">
      <q-card class="bg-white">
        <n-header icon="local_shipping">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('Supplier') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 col-sm-6"><n-name :name="form.name" @update:name="form.name = $event" icon="local_shipping" :label="$t('Name')" autofocus /></div>
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
  { name: 'balance', label: 'Balance', field: 'balance', align: 'right', sortable: true },
  { name: 'active', label: 'Status', field: 'active', align: 'center' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' }
]

const totalPayable = computed(() => rows.value.reduce((s, r) => s + Number(r.balance || 0), 0))
const activeCount = computed(() => rows.value.filter(r => r.active).length)

function fmt (v) {
  return `${Number(v || 0).toLocaleString()} AFN`
}

const blank = () => ({ id: null, name: '', phone: '', email: '', address: '', active: true })
const form = reactive(blank())

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/suppliers', { params: { search: search.value } })
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
    if (form.id) await api.put('/suppliers/' + form.id, payload)
    else await api.post('/suppliers', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    dialog.value = false
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

function remove (id) { proxy.$delete('suppliers/' + id, load) }

onMounted(load)
</script>
