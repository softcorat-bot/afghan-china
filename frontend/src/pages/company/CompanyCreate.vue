<template>
  <q-card class="q-pa-md shadow-2" style="width: 640px; max-width: 95vw">
    <q-card-section>
      <div class="text-h5 text-primary text-weight-bold">Create Your Company</div>
      <div class="text-grey-6">Set up your company to get started</div>
    </q-card-section>

    <!-- Business Type Selection (Step 0) -->
    <div v-if="step === 0">
      <div class="text-subtitle1 text-weight-bold text-grey-8 text-center q-mb-md">Select your business type</div>
      <div class="row q-col-gutter-sm justify-center">
        <div class="col-6 col-sm-4" v-for="bt in businessTypes" :key="bt.value">
          <q-card class="cursor-pointer text-center q-pa-sm my_radio_less three_d_plan"
            style="transition: all .15s; border: 2px solid transparent"
            :style="form.business_type === bt.value ? `border-color: var(--q-${bt.color}, #175A8C)` : ''"
            :class="form.business_type === bt.value ? `bg-${bt.color}-1` : 'bg-white'"
            @click="form.business_type = bt.value; step = 1">
            <q-icon :name="bt.icon" size="28px" :color="bt.color + '-7'" />
            <div class="text-caption text-weight-medium q-mt-xs" style="font-size:11px;line-height:1.3">{{ bt.label }}</div>
          </q-card>
        </div>
      </div>
    </div>

    <q-stepper v-if="step > 0" v-model="step" color="primary" animated flat>
      <q-step :name="1" title="Basic Info" icon="business" :done="step > 1">
        <div class="q-gutter-md">
          <q-input v-model="form.name_en" label="Company Name (English) *" outlined dense />
          <q-input v-model="form.name_fa" label="Company Name (Farsi)" outlined dense />
          <q-input v-model="form.abbreviation" label="Abbreviation" outlined dense />
        </div>
      </q-step>

      <q-step :name="2" title="Contact" icon="contacts" :done="step > 2">
        <div class="q-gutter-md">
          <q-input v-model="form.phone" label="Phone" outlined dense />
          <q-input v-model="form.email" type="email" label="Email" outlined dense />
          <q-input v-model="form.address" label="Address" outlined dense autogrow />
        </div>
      </q-step>

      <q-step :name="3" title="Financial Year" icon="event" :done="step > 3">
        <div class="q-gutter-md">
          <q-input v-model="form.financial_start_date" label="Financial Start Date" outlined dense readonly>
            <template #append>
              <q-icon name="event" class="cursor-pointer">
                <q-popup-proxy cover transition-show="scale" transition-hide="scale">
                  <q-date v-model="form.financial_start_date" mask="YYYY-MM-DD" @update:model-value="setEndDate">
                    <div class="row items-center justify-end">
                      <q-btn v-close-popup label="Close" color="primary" flat />
                    </div>
                  </q-date>
                </q-popup-proxy>
              </q-icon>
            </template>
          </q-input>

          <q-input v-model="form.financial_end_date" label="Financial End Date" outlined dense readonly>
            <template #append>
              <q-icon name="event" class="cursor-pointer">
                <q-popup-proxy cover transition-show="scale" transition-hide="scale">
                  <q-date v-model="form.financial_end_date" mask="YYYY-MM-DD">
                    <div class="row items-center justify-end">
                      <q-btn v-close-popup label="Close" color="primary" flat />
                    </div>
                  </q-date>
                </q-popup-proxy>
              </q-icon>
            </template>
          </q-input>

          <q-select v-model="form.currency" :options="currencies" label="Currency" outlined dense emit-value map-options />
        </div>
      </q-step>

      <template #navigation>
        <q-stepper-navigation class="row justify-between">
          <q-btn flat color="grey-6" label="Back" @click="step <= 1 ? step = 0 : step--" />
          <q-space />
          <q-btn
            v-if="step < 3"
            color="primary"
            label="Continue"
            :disable="step === 1 && !form.name_en"
            @click="step++"
          />
          <q-btn
            v-else
            color="primary"
            label="Create Company"
            :loading="loading"
            @click="submit"
          />
        </q-stepper-navigation>
      </template>
    </q-stepper>
  </q-card>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useQuasar, date } from 'quasar'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const $q = useQuasar()
const auth = useAuthStore()

const step = ref(0)

const businessTypes = [
  { value: 'retail', label: 'Retail / Shop', icon: 'storefront', color: 'blue' },
  { value: 'supermarket', label: 'Supermarket', icon: 'shopping_cart', color: 'green' },
  { value: 'wholesale', label: 'Wholesale & Distribution', icon: 'local_shipping', color: 'orange' },
  { value: 'general_trading', label: 'General Trading', icon: 'storefront', color: 'brown' },
]
const loading = ref(false)
const currencies = ['AFN', 'USD', 'CNY']

const today = date.formatDate(Date.now(), 'YYYY-MM-DD')
const form = reactive({
  name_en: '', name_fa: '', abbreviation: '',
  phone: '', email: '', address: '',
  financial_start_date: today,
  financial_end_date: date.formatDate(date.addToDate(today, { year: 1 }), 'YYYY-MM-DD'),
  currency: 'AFN',
  business_type: '',
})

function setEndDate (val) {
  form.financial_end_date = date.formatDate(date.addToDate(new Date(val), { year: 1 }), 'YYYY-MM-DD')
}

async function submit () {
  loading.value = true
  try {
    await api.post('/company/store', form)
    await auth.fetchUser()
    $q.notify({ type: 'positive', message: 'Company created!' })
    router.push({ name: 'dashboard' })
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Failed to create company' })
  } finally {
    loading.value = false
  }
}
</script>
