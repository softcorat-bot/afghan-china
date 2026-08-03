<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <m-header icon="badge" controlRoomButton="false" class="q-mt-xs">{{ $t('Roles') }}</m-header>
        </div>

        <!-- Summary -->
        <div class="col-12 q-mt-sm">
          <div class="row q-col-gutter-md">
            <div class="col-6 col-sm-4"><stat-card dense icon="badge" :label="$t('TotalRoles')" :value="rows.length" color="#175A8C" tint="#E0EDF7" /></div>
            <div class="col-6 col-sm-4"><stat-card dense icon="verified_user" :label="$t('AvailablePermissions')" :value="totalPermissions" color="#0D9488" tint="#CCFBF1" /></div>
            <div class="col-6 col-sm-4"><stat-card dense icon="group" :label="$t('AssignedUsers')" :value="totalUsers" color="#7C3AED" tint="#EDE9FE" /></div>
          </div>
        </div>

        <action-bar :rows="rows" :columns="columns" filename="roles" create-perm="role-create" @add="openCreate" @update:filtered="filteredRows = $event" />

        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="tableFilter"
            :can_edit="'role-edit'" :can_delete="'role-delete'" @edit="openEdit" @del="remove">
            <template v-slot:body-cell-name="props">
              <q-td :props="props">
                <div class="row items-center no-wrap">
                  <q-avatar size="32px" class="q-mr-sm" :style="`background:${roleColor(props.row.name)}1a;color:${roleColor(props.row.name)}`">
                    <q-icon name="badge" size="18px" />
                  </q-avatar>
                  <a class="role-link" @click.prevent="openEdit(props.row.id)">{{ props.row.name }}</a>
                </div>
              </q-td>
            </template>
            <template v-slot:body-cell-modules_count="props">
              <q-td :props="props" class="text-center">
                <q-chip dense size="sm" color="blue-grey-1" text-color="blue-grey-8">{{ props.row.modules_count }} {{ $t('Modules') }}</q-chip>
              </q-td>
            </template>
            <template v-slot:body-cell-permissions_count="props">
              <q-td :props="props" class="text-center">
                <q-chip dense size="sm" color="teal-1" text-color="teal-9"><q-icon name="verified_user" size="13px" class="q-mr-xs" />{{ props.row.permissions_count }}</q-chip>
              </q-td>
            </template>
            <template v-slot:body-cell-users_count="props">
              <q-td :props="props" class="text-center">
                <q-chip dense size="sm" :color="props.row.users_count ? 'deep-purple-1' : 'grey-2'" :text-color="props.row.users_count ? 'deep-purple-9' : 'grey-6'">
                  <q-icon name="group" size="13px" class="q-mr-xs" />{{ props.row.users_count }}
                </q-chip>
              </q-td>
            </template>
          </n-table>
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, computed, getCurrentInstance, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()
const router = useRouter()

const rows = ref([])
const loading = ref(false)
const tableFilter = ref('')
const filteredRows = ref([])
const totalPermissions = ref(0)

const totalUsers = computed(() => rows.value.reduce((s, r) => s + (r.users_count || 0), 0))

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'name', label: 'RoleName', field: 'name', align: 'left', sortable: true },
  { name: 'modules_count', label: 'Modules', field: 'modules_count', align: 'center', sortable: true },
  { name: 'permissions_count', label: 'Permissions', field: 'permissions_count', align: 'center', sortable: true },
  { name: 'users_count', label: 'AssignedUsers', field: 'users_count', align: 'center', sortable: true },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' }
]

const palette = ['#175A8C', '#0D9488', '#7C3AED', '#D97706', '#DC2626', '#0284C7', '#B45309', '#059669']
function roleColor (name) {
  let h = 0
  for (let i = 0; i < (name || '').length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0
  return palette[h % palette.length]
}

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/roles')
    rows.value = data
  } finally { loading.value = false }
  try { const { data } = await api.get('/permissions'); totalPermissions.value = (data || []).length } catch (_) {}
}

function openCreate () { router.push('/roles/create') }
function openEdit (id) { router.push(`/roles/edit/${id}`) }
function remove (id) { proxy.$delete(`roles/${id}`, load) }

onMounted(load)
</script>

<style scoped>
.role-link { color: var(--q-primary); font-weight: 600; cursor: pointer; text-decoration: none; }
.role-link:hover { text-decoration: underline; }
</style>
