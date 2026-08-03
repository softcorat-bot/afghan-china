<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">

        <!-- Page header + back -->
        <div class="col-12">
          <div class="row items-center justify-between">
            <div class="col">
              <m-header icon="person" controlRoomButton="false"
                :subtitle="editing ? $t('Edit') + (form.name ? ' — ' + form.name : '') : $t('AddNew')"
                class="q-mt-xs">
                {{ $t('User') }}
              </m-header>
            </div>
            <div class="col-auto">
              <q-btn flat dense icon="arrow_back" color="primary" :label="$t('Back')" @click="goBack" />
            </div>
          </div>
        </div>

        <div class="col-12">
          <q-form @submit="save" @reset="resetForm">
            <q-card class="my_radio_less bg-white">

              <!-- Quick info bar -->
              <div class="user-info-bar row items-center q-px-md q-py-xs"
                style="background:#f5f7fa;border-bottom:1px solid #e0e0e0;gap:16px">
                <div class="row items-center q-gutter-xs">
                  <q-icon name="account_box" size="14px" color="grey-6" />
                  <span class="text-caption text-grey-8 text-weight-medium">{{ form.name || '—' }}</span>
                </div>
                <div class="row items-center q-gutter-xs">
                  <q-icon name="email" size="14px" color="grey-6" />
                  <span class="text-caption text-grey-7">{{ form.email || '—' }}</span>
                </div>
                <q-space />
              </div>

              <q-card-section class="q-pa-md">
                <div class="row q-col-gutter-sm">
                  <div class="col-12 col-sm-6">
                    <n-name :name="form.name" @update:name="form.name = $event" icon="person"
                      :label="$t('Name')" autofocus />
                  </div>
                  <div class="col-12 col-sm-6">
                    <n-name :name="form.email" @update:name="form.email = $event" icon="email"
                      type="email" :label="$t('Email')" />
                  </div>
                  <div class="col-12 col-sm-6">
                    <n-name
                      :name="form.password" @update:name="form.password = $event"
                      icon="lock" type="password"
                      :label="editing ? $t('NewPasswordOptional') : $t('Password')"
                      :rules="editing ? [] : [val => !!val || $t('FieldIsRequired')]" />
                  </div>
                  <div class="col-12 col-sm-6">
                    <q-input outlined dense color="primary" label-color="primary" class="q-mt-sm"
                      v-model="form.pin" mask="######" inputmode="numeric"
                      :label="$t('CounterPin')" :hint="$t('CounterPinFieldHint')">
                      <template #prepend><q-icon name="dialpad" color="primary" /></template>
                    </q-input>
                  </div>
                  <div class="col-12 col-sm-6">
                    <q-select outlined dense color="primary" label-color="primary"
                      v-model="form.roles" :options="roleOptions"
                      multiple emit-value map-options use-chips :label="$t('Roles')">
                      <template v-slot:prepend><q-icon name="badge" color="primary" /></template>
                    </q-select>
                  </div>
                </div>
              </q-card-section>
            </q-card>

            <!-- Sticky Save banner -->
            <div class="user-save-banner row items-center justify-end q-gutter-sm q-px-md q-py-sm">
              <q-btn flat :label="$t('Cancel')" color="grey-7" icon="close" @click="goBack" />
              <q-btn flat :label="$t('Reset')" color="orange-8" icon="restart_alt" type="reset" />
              <q-btn unelevated :label="$t('Save')" color="primary" icon="save" type="submit" :loading="saving" />
            </div>
          </q-form>
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const route = useRoute()
const router = useRouter()

const editing = computed(() => !!route.params.id)
const saving = ref(false)
const roleOptions = ref([])

const blank = () => ({ name: '', email: '', password: '', pin: '', roles: [] })
const form = reactive(blank())

function resetForm () { Object.assign(form, blank()) }

async function loadRoles () {
  const { data } = await api.get('/roles')
  roleOptions.value = data.map(role => ({ label: role.name, value: role.name }))
}

// Users resource has no `show` route → load the list and find the record.
async function loadUser () {
  try {
    const { data } = await api.get('/users')
    const row = data.find(r => String(r.id) === String(route.params.id))
    if (!row) {
      Notify.create({ type: 'negative', message: 'User not found' })
      return router.push('/users')
    }
    Object.assign(form, {
      name: row.name,
      email: row.email,
      password: '',
      pin: '',
      roles: row.roles.map(r => r.name)
    })
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Load failed' })
  }
}

async function save () {
  saving.value = true
  try {
    const payload = { name: form.name, email: form.email, roles: form.roles }
    if (form.password) payload.password = form.password
    let userId = route.params.id
    if (editing.value) await api.put(`/users/${userId}`, payload)
    else {
      const { data } = await api.post('/users', payload)
      userId = data?.id ?? data?.user?.id
    }
    // PIN travels on its own endpoint (hashed, never echoed back)
    if (form.pin && userId) {
      try { await api.put(`/users/${userId}/pin`, { pin: form.pin }) } catch (_) {}
    }
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved successfully' })
    router.push('/users')
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally {
    saving.value = false
  }
}

function goBack () { router.push('/users') }

onMounted(() => {
  loadRoles()
  if (editing.value) loadUser()
})
</script>

<style scoped>
.user-save-banner {
  position: sticky;
  bottom: 0;
  background: var(--surface-card);
  border-top: 1px solid #e0e0e0;
  border-radius: 0 0 10px 10px;
  margin-top: 8px;
  z-index: 5;
}
</style>
