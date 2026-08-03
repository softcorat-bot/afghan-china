<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="payments" controlRoomButton="false" class="q-mt-xs">
            {{ $t('Currencies') }}
          </m-header>
        </div>

        <div class="col-12 q-mt-xs" v-if="$can('currency-create')">
          <progress-btn color="teal" icon="add" @click="openCreate">{{ $t('AddNew') }}</progress-btn>
        </div>

        <div class="col-12 q-mt-sm">
          <q-markup-table flat bordered dense class="my_radio_less">
            <thead class="bg-theme-soft">
              <tr>
                <th class="text-left">{{ $t('Code') }}</th>
                <th class="text-left">{{ $t('Name') }}</th>
                <th class="text-left">{{ $t('Symbol') }}</th>
                <th class="text-center">{{ $t('BaseCurrency') }}</th>
                <th class="text-center">{{ $t('Status') }}</th>
                <th class="text-right">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading"><td colspan="6" class="text-center q-py-md"><q-spinner color="primary" size="2em" /></td></tr>
              <tr v-else-if="rows.length === 0"><td colspan="6" class="text-center text-grey-5 q-py-md">{{ $t('NoRecordFound') }}</td></tr>
              <tr v-for="c in rows" :key="c.id">
                <td class="text-weight-bold">{{ c.code }}</td>
                <td>{{ c.name }}</td>
                <td>{{ c.symbol || '—' }}</td>
                <td class="text-center">
                  <q-icon v-if="c.is_base" name="star" color="amber-8" size="18px"><q-tooltip>{{ $t('BaseCurrency') }}</q-tooltip></q-icon>
                  <q-btn v-else-if="$can('currency-edit')" flat dense size="sm" color="grey-7" :label="$t('SetBase')" @click="setBase(c)" />
                  <span v-else>—</span>
                </td>
                <td class="text-center">
                  <q-chip dense size="sm" :color="c.active ? 'positive' : 'grey'" text-color="white">
                    {{ c.active ? $t('Active') : $t('Inactive') }}
                  </q-chip>
                </td>
                <td class="text-right" style="white-space:nowrap">
                  <q-btn size="sm" dense flat round icon="edit" color="blue-8" v-if="$can('currency-edit')" @click="openEdit(c)" />
                  <q-btn size="sm" dense flat round icon="delete" color="negative" v-if="$can('currency-delete') && !c.is_base" @click="remove(c)" />
                </td>
              </tr>
            </tbody>
          </q-markup-table>
        </div>
      </div>
    </m-backgrounds>

    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 420px">
      <q-card class="bg-white">
        <n-header icon="payments">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('Currency') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-6"><n-name :name="form.code" @update:name="form.code = $event" icon="tag" :label="$t('Code')" autofocus /></div>
            <div class="col-6"><n-name :name="form.symbol" @update:name="form.symbol = $event" icon="attach_money" :label="$t('Symbol')" :rules="[]" /></div>
            <div class="col-12"><n-name :name="form.name" @update:name="form.name = $event" icon="badge" :label="$t('Name')" /></div>
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
import { ref, reactive, getCurrentInstance, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()
const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const dialog = ref(false)

const blank = () => ({ id: null, code: '', name: '', symbol: '', active: true })
const form = reactive(blank())

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/currencies')
    rows.value = data
  } finally { loading.value = false }
}

function openCreate () { Object.assign(form, blank()); dialog.value = true }
function openEdit (c) {
  Object.assign(form, { id: c.id, code: c.code, name: c.name, symbol: c.symbol || '', active: !!c.active })
  dialog.value = true
}

async function save () {
  saving.value = true
  try {
    const payload = { code: form.code, name: form.name, symbol: form.symbol, active: form.active }
    if (form.id) await api.put('/currencies/' + form.id, payload)
    else await api.post('/currencies', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    dialog.value = false
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

async function setBase (c) {
  try {
    await api.put('/currencies/' + c.id + '/set-base')
    Notify.create({ type: 'positive', position: 'bottom', message: `${c.code} is now the base currency` })
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })
  }
}

function remove (c) { proxy.$delete('currencies/' + c.id, load) }

onMounted(load)
</script>
