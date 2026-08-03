<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="currency_exchange" controlRoomButton="false" class="q-mt-xs">
            {{ $t('ExchangeRates') }}
          </m-header>
        </div>

        <!-- Rate-lock explainer -->
        <div class="col-12">
          <q-banner rounded class="bg-blue-1 text-blue-9 text-caption">
            <template #avatar><q-icon name="lock" color="blue-8" /></template>
            {{ $t('RateLockNote') }}
          </q-banner>
        </div>

        <!-- Current rates -->
        <div class="col-12">
          <div class="text-caption text-grey-6 q-mb-xs">{{ $t('CurrentRate') }} — {{ $t('BaseCurrency') }}: <b>{{ base }}</b></div>
          <div class="row q-col-gutter-sm">
            <div class="col-12 col-sm-4" v-for="r in current" :key="r.currency_code">
              <q-card class="my_radio_less three_d_plan q-pa-md">
                <div class="text-h6 text-weight-bold text-primary">
                  1 {{ r.currency_code }} = {{ r.rate_to_base != null ? fmt(r.rate_to_base) : '—' }} {{ base }}
                </div>
                <div class="text-caption text-grey-6">
                  {{ r.name }} · {{ r.rate_date ? $t('RateDate') + ': ' + r.rate_date : $t('NoRecordFound') }}
                </div>
              </q-card>
            </div>
            <div v-if="current.length === 0" class="col-12 text-grey-5 q-pa-md">{{ $t('NoRecordFound') }}</div>
          </div>
        </div>

        <!-- Set today's rate -->
        <div class="col-12" v-if="$can('exchange-rate-create')">
          <q-card class="my_radio_less">
            <n-header icon="edit" flat>{{ $t('SetRate') }}</n-header>
            <q-form @submit="saveRate">
              <q-card-section class="row q-col-gutter-sm items-end">
                <div class="col-6 col-sm-3">
                  <q-select outlined dense color="primary" label-color="primary"
                    v-model="rateForm.currency_code" :options="foreignOptions" emit-value map-options :label="$t('Currency')" />
                </div>
                <div class="col-6 col-sm-3">
                  <q-input outlined dense color="primary" type="number" step="0.0001" v-model.number="rateForm.rate_to_base"
                    :label="`1 ${rateForm.currency_code || '—'} = ? ${base}`" :rules="[v => v > 0 || $t('FieldIsRequired')]" hide-bottom-space />
                </div>
                <div class="col-6 col-sm-3">
                  <q-input outlined dense color="primary" v-model="rateForm.rate_date" :label="$t('RateDate')" readonly>
                    <template #append>
                      <q-icon name="event" class="cursor-pointer">
                        <q-popup-proxy cover transition-show="scale" transition-hide="scale">
                          <q-date v-model="rateForm.rate_date" mask="YYYY-MM-DD">
                            <div class="row items-center justify-end"><q-btn v-close-popup :label="$t('Close')" color="primary" flat /></div>
                          </q-date>
                        </q-popup-proxy>
                      </q-icon>
                    </template>
                  </q-input>
                </div>
                <div class="col-6 col-sm-3">
                  <q-btn unelevated color="primary" icon="save" :label="$t('Save')" type="submit" :loading="saving" class="full-width" />
                </div>
              </q-card-section>
            </q-form>
          </q-card>
        </div>

        <!-- History -->
        <div class="col-12">
          <div class="text-caption text-grey-6 q-mb-xs">{{ $t('History') }}</div>
          <q-markup-table flat bordered dense class="my_radio_less">
            <thead class="bg-theme-soft">
              <tr>
                <th class="text-left">{{ $t('RateDate') }}</th>
                <th class="text-left">{{ $t('Currency') }}</th>
                <th class="text-right">{{ $t('Rate') }}</th>
                <th class="text-left">{{ $t('CreatedBy') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="loading"><td colspan="4" class="text-center q-py-md"><q-spinner color="primary" size="2em" /></td></tr>
              <tr v-else-if="history.length === 0"><td colspan="4" class="text-center text-grey-5 q-py-md">{{ $t('NoRecordFound') }}</td></tr>
              <tr v-for="h in history" :key="h.id">
                <td style="white-space:nowrap">{{ h.rate_date ? h.rate_date.slice(0,10) : '—' }}</td>
                <td>{{ h.currency_code }}</td>
                <td class="text-right text-weight-medium">1 {{ h.currency_code }} = {{ fmt(h.rate_to_base) }} {{ base }}</td>
                <td class="text-caption text-blue-grey-7">{{ h.user?.name || '—' }}</td>
              </tr>
            </tbody>
          </q-markup-table>
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const loading = ref(false)
const saving = ref(false)
const base = ref('AFN')
const current = ref([])
const history = ref([])

const rateForm = reactive({ currency_code: '', rate_to_base: null, rate_date: new Date().toISOString().slice(0, 10) })

const foreignOptions = computed(() => current.value.map(c => ({ label: `${c.currency_code} — ${c.name}`, value: c.currency_code })))

function fmt (v) { return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 }) }

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/exchange-rates')
    base.value = data.base || 'AFN'
    current.value = data.current || []
    history.value = data.history || []
    if (!rateForm.currency_code && current.value.length) rateForm.currency_code = current.value[0].currency_code
  } catch (_) {} finally { loading.value = false }
}

async function saveRate () {
  saving.value = true
  try {
    await api.post('/exchange-rates', { currency_code: rateForm.currency_code, rate_to_base: rateForm.rate_to_base, rate_date: rateForm.rate_date })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    rateForm.rate_to_base = null
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}

onMounted(load)
</script>
