<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="manage_search" controlRoomButton="false" class="q-mt-xs">
            {{ $t('Log') }}
          </m-header>
        </div>

        <!-- Server-side filter bar: person, action, module, dates, text -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-sm items-center q-pa-sm bg-blue-grey-1 my_radio_less" style="border-radius:10px">
            <div class="col-6 col-md-2">
              <q-select outlined dense color="primary" bg-color="white" v-model="filters.user_id" :options="userOptions" emit-value map-options
                :label="$t('User')" clearable @update:model-value="load">
                <template #prepend><q-icon name="person" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-md-2">
              <q-select outlined dense color="primary" bg-color="white" v-model="filters.action" :options="actionOptions" emit-value map-options
                :label="$t('Action')" clearable @update:model-value="load">
                <template #prepend><q-icon name="filter_list" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-md-2">
              <q-select outlined dense color="primary" bg-color="white" v-model="filters.module" :options="moduleOptions"
                :label="$t('Module')" clearable @update:model-value="load">
                <template #prepend><q-icon name="category" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-6 col-md-2">
              <q-input outlined dense color="primary" bg-color="white" v-model="filters.from" type="date" :label="$t('From')" @update:model-value="load" />
            </div>
            <div class="col-6 col-md-2">
              <q-input outlined dense color="primary" bg-color="white" v-model="filters.to" type="date" :label="$t('To')" @update:model-value="load" />
            </div>
            <div class="col-6 col-md-2">
              <q-input outlined dense color="primary" bg-color="white" v-model="filters.search" :label="$t('Search')" clearable debounce="350" @update:model-value="load">
                <template #prepend><q-icon name="search" color="primary" /></template>
              </q-input>
            </div>
          </div>
        </div>

        <div class="col-12 q-mt-sm">
          <q-markup-table flat bordered dense class="my_radio_less">
            <thead class="bg-theme-soft">
              <tr>
                <th class="text-left" style="width:40px">#</th>
                <th class="text-left">{{ $t('Action') }}</th>
                <th class="text-left">{{ $t('Module') }}</th>
                <th class="text-left">{{ $t('Description') }}</th>
                <th class="text-left">{{ $t('User') }}</th>
                <th class="text-left">{{ $t('Time') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading">
                <td colspan="6" class="text-center q-py-md"><q-spinner color="primary" size="2em" /></td>
              </tr>
              <tr v-else-if="rows.length === 0">
                <td colspan="6" class="text-center text-grey-5 q-py-md">{{ $t('NoRecordFound') }}</td>
              </tr>
              <tr v-else v-for="(r, i) in rows" :key="r.id">
                <td class="text-grey-5 text-caption">{{ i + 1 }}</td>
                <td>
                  <q-chip dense size="sm" :color="actionColor(r.action)" text-color="white" class="q-ma-none">
                    {{ r.action }}
                  </q-chip>
                </td>
                <td>
                  <q-chip dense size="sm" color="grey-3" text-color="grey-8" class="q-ma-none">
                    <q-icon :name="moduleIcon(r.module)" size="12px" class="q-mr-xs" />
                    {{ r.module }}
                  </q-chip>
                </td>
                <td class="text-caption">{{ r.description }}</td>
                <td class="text-caption text-blue-grey-7">{{ r.user?.name ?? '—' }}</td>
                <td class="text-caption text-grey-6" style="white-space:nowrap">{{ r.created_at_human ?? r.created_at?.slice(0, 16) }}</td>
              </tr>
            </tbody>
          </q-markup-table>
        </div>

        <div class="col-12 q-mt-xs text-caption text-grey-6">
          {{ rows.length }} {{ $t('Entries') }}
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '@/boot/axios'

const rows = ref([])
const loading = ref(false)
const moduleOptions = ref([])
const userOptions = ref([])

const filters = reactive({ user_id: null, action: null, module: null, from: '', to: '', search: '' })

const actionOptions = [
  { label: 'Created', value: 'created' },
  { label: 'Updated', value: 'updated' },
  { label: 'Deleted', value: 'deleted' },
  { label: 'Restored', value: 'restored' },
]

function actionColor (action) {
  return { created: 'positive', updated: 'warning', deleted: 'negative', restored: 'teal' }[action] ?? 'grey'
}

function moduleIcon (module) {
  const map = {
    User: 'manage_accounts', Role: 'rule', Branch: 'store', Company: 'business',
    Product: 'inventory_2', ProductCategory: 'category', Customer: 'groups',
    Sale: 'sell', Pos: 'point_of_sale', Shift: 'savings', Refund: 'undo',
    Supplier: 'local_shipping', Purchase: 'shopping_cart', StockAdjustment: 'tune',
    Currency: 'payments', ExchangeRate: 'currency_exchange',
    Backup: 'backup', Settings: 'settings',
  }
  return map[module] ?? 'circle'
}

async function load () {
  loading.value = true
  try {
    const params = {}
    for (const k of ['user_id', 'action', 'module', 'from', 'to', 'search']) if (filters[k]) params[k] = filters[k]
    const { data } = await api.get('/activity-logs', { params })
    rows.value = data.logs || []
    moduleOptions.value = data.modules || []
    userOptions.value = (data.users || []).map(u => ({ label: u.name, value: u.id }))
  } catch (_) {}
  finally { loading.value = false }
}

onMounted(load)
</script>
