<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="people" controlRoomButton="false" class="q-mt-xs">{{ $t('Users') }}</m-header>
        </div>

        <action-bar :rows="rows" :columns="columns" filename="users" create-perm="user-create" @add="openCreate" @update:filtered="filteredRows = $event" />
        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            :can_edit="'user-edit'" :can_delete="'user-delete'" @edit="openEdit" @del="remove">
            <template v-slot:body-cell-name="props">
              <q-td :props="props">
                <span class="text-weight-medium text-primary cursor-pointer" style="text-decoration:underline dotted"
                  @click.stop="$router.push('/users/' + props.row.id + '/performance')">{{ props.row.name }}</span>
              </q-td>
            </template>
            <template v-slot:body-cell-roles="props">
              <q-td :props="props">
                <q-chip v-for="r in props.row.roles" :key="r.id" size="sm" color="primary" text-color="white">{{ r.name }}</q-chip>
                <span v-if="!props.row.roles?.length" class="text-grey-5">—</span>
              </q-td>
            </template>
            <template v-slot:body-cell-branches="props">
              <q-td :props="props">
                <q-chip v-for="p in props.row.branches" :key="p.id" dense size="sm" color="blue-grey-2" text-color="blue-grey-9">{{ p.name }}</q-chip>
                <span v-if="!props.row.branches?.length" class="text-grey-5">{{ $t('AllBranches') }}</span>
              </q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>

    <!-- Add / edit user (modal) -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 560px">
      <q-card class="bg-white">
        <n-header icon="person">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('User') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 flex justify-center q-mb-xs" v-if="form.id">
              <avatar-box type="user" :id="form.id" :name="form.name" :size="84" />
            </div>
            <div class="col-12 col-sm-6"><n-name :name="form.name" @update:name="form.name = $event" icon="person" :label="$t('Name')" /></div>
            <div class="col-12 col-sm-6"><n-name :name="form.email" @update:name="form.email = $event" icon="email" :label="$t('Email')" /></div>
            <div class="col-12 col-sm-6">
              <q-input outlined dense color="primary" type="password" v-model="form.password" :label="$t('Password')" :hint="form.id ? $t('LeaveBlankKeep') : ''">
                <template #prepend><q-icon name="lock" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-6">
              <q-select outlined dense color="primary" v-model="form.roles" :options="roleOptions" multiple use-chips :label="$t('Roles')" emit-value map-options>
                <template #prepend><q-icon name="badge" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-12">
              <q-select outlined dense color="primary" v-model="form.branch_ids" :options="branchOptions" multiple use-chips emit-value map-options :label="$t('AssignedBranches')" :hint="$t('AssignedBranchesHint')">
                <template #prepend><q-icon name="store" color="primary" /></template>
              </q-select>
            </div>
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
const filteredRows = ref([])
const loading = ref(false)
const saving = ref(false)
const filter = ref('')
const dialog = ref(false)
const roleOptions = ref([])
const branchOptions = ref([])

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'name', label: 'Name', field: 'name', align: 'left', sortable: true },
  { name: 'email', label: 'Email', field: 'email', align: 'left', sortable: true },
  { name: 'roles', label: 'Roles', field: 'roles', align: 'left' },
  { name: 'branches', label: 'AssignedBranches', field: 'branches', align: 'left' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' }
]

const blank = () => ({ id: null, name: '', email: '', password: '', roles: [], branch_ids: [] })
const form = reactive(blank())

async function load () {
  loading.value = true
  try { const { data } = await api.get('/users'); rows.value = data } finally { loading.value = false }
}
async function loadMeta () {
  try { const { data } = await api.get('/roles'); roleOptions.value = (data || []).map(r => ({ label: r.name, value: r.name })) } catch (_) {}
  try { const { data } = await api.get('/branches'); branchOptions.value = (data || []).map(p => ({ label: p.name, value: p.id })) } catch (_) {}
}

function openCreate () { Object.assign(form, blank()); dialog.value = true }
function openEdit (id) {
  const r = rows.value.find(x => x.id === id); if (!r) return
  Object.assign(form, { id: r.id, name: r.name, email: r.email, password: '', roles: (r.roles || []).map(x => x.name), branch_ids: (r.branches || []).map(p => p.id) })
  dialog.value = true
}
async function save () {
  saving.value = true
  try {
    const payload = { name: form.name, email: form.email, roles: form.roles, branch_ids: form.branch_ids }
    if (form.password) payload.password = form.password
    if (form.id) await api.put('/users/' + form.id, payload)
    else await api.post('/users', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    dialog.value = false; load()
  } catch (e) { Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' }) } finally { saving.value = false }
}
function remove (id) { proxy.$delete('users/' + id, load) }

onMounted(() => { load(); loadMeta() })
</script>
