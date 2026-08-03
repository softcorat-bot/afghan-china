<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">

        <!-- Page header + back -->
        <div class="col-12">
          <div class="row items-center justify-between">
            <div class="col">
              <m-header icon="badge" controlRoomButton="false"
                :subtitle="editing ? $t('Edit') : $t('AddNew')"
                class="q-mt-xs">
                {{ $t('Role') }}
              </m-header>
            </div>
            <div class="col-auto">
              <q-btn flat dense icon="arrow_back" color="primary" :label="$t('Back')" @click="goBack" />
            </div>
          </div>
        </div>

        <div class="col-12">
          <q-form @submit="save">
            <q-card class="my_radio_less bg-white">

              <!-- Name + permission toolbar -->
              <q-card-section class="q-pb-none">
                <div class="row q-col-gutter-sm items-center">
                  <div class="col-12 col-sm-5">
                    <n-name :name="current.name" @update:name="current.name = $event" icon="badge" :label="$t('RoleName')" autofocus />
                  </div>
                  <div class="col-12 col-sm-7">
                    <div class="row items-center q-gutter-sm justify-end">
                      <q-chip dense outline color="primary" :label="`${current.permissions.length} ${$t('Selected')}`" />
                      <q-btn size="sm" outline color="primary" :label="$t('SelectAll')" @click="selectAll" />
                      <q-btn size="sm" outline color="grey-7" :label="$t('Clear')" @click="current.permissions = []" />
                    </div>
                  </div>
                  <div class="col-12">
                    <q-input v-model="filter" :label="$t('FilterModules')" outlined dense clearable color="primary">
                      <template #prepend><q-icon name="search" color="primary" /></template>
                    </q-input>
                  </div>
                </div>
              </q-card-section>

              <!-- Permission matrix -->
              <q-card-section class="q-pt-sm">
                <q-scroll-area style="height: 420px">
                  <div class="row items-center text-caption text-weight-bold text-grey-7 q-px-sm q-py-xs perm-header bg-theme-soft">
                    <div class="col-4">{{ $t('Module') }}</div>
                    <div v-for="a in actions" :key="a" class="col text-center text-capitalize">{{ a }}</div>
                    <div class="col-1 text-center">{{ $t('All') }}</div>
                  </div>

                  <q-list separator dense>
                    <q-item v-for="group in filteredGroups" :key="group.entity" class="q-px-sm">
                      <q-item-section class="col-4">
                        <span class="text-capitalize">{{ group.label }}</span>
                      </q-item-section>
                      <q-item-section v-for="a in actions" :key="a" class="col text-center">
                        <q-checkbox
                          v-if="group.byAction[a]"
                          :model-value="current.permissions.includes(group.byAction[a])"
                          color="primary"
                          dense
                          @update:model-value="toggle(group.byAction[a], $event)"
                        />
                        <span v-else class="text-grey-4">—</span>
                      </q-item-section>
                      <q-item-section class="col-1 text-center">
                        <q-checkbox
                          :model-value="groupAllSelected(group)"
                          :indeterminate-value="'mixed'"
                          color="primary"
                          dense
                          @update:model-value="toggleGroup(group, $event)"
                        />
                      </q-item-section>
                    </q-item>
                  </q-list>
                </q-scroll-area>
              </q-card-section>
            </q-card>

            <!-- Sticky Save banner -->
            <div class="role-save-banner row items-center justify-end q-gutter-sm q-px-md q-py-sm">
              <q-btn flat :label="$t('Cancel')" color="grey-7" icon="close" @click="goBack" />
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
const filter = ref('')
const permissions = ref([])
const current = reactive({ name: '', permissions: [] })

const actions = ['list', 'create', 'edit', 'show', 'delete']

function splitPermission (name) {
  const idx = name.lastIndexOf('-')
  return { entity: name.slice(0, idx), action: name.slice(idx + 1) }
}

function prettify (entity) {
  return entity.replace(/-/g, ' ')
}

const groups = computed(() => {
  const map = new Map()
  for (const p of permissions.value) {
    const { entity, action } = splitPermission(p.name)
    if (!map.has(entity)) map.set(entity, { entity, label: prettify(entity), byAction: {} })
    map.get(entity).byAction[action] = p.name
  }
  return Array.from(map.values()).sort((a, b) => a.label.localeCompare(b.label))
})

const filteredGroups = computed(() => {
  if (!filter.value) return groups.value
  const needle = filter.value.toLowerCase()
  return groups.value.filter(g => g.label.toLowerCase().includes(needle))
})

function groupPermNames (group) {
  return actions.map(a => group.byAction[a]).filter(Boolean)
}

function groupAllSelected (group) {
  const names = groupPermNames(group)
  const selected = names.filter(n => current.permissions.includes(n)).length
  if (selected === 0) return false
  if (selected === names.length) return true
  return 'mixed'
}

function toggle (name, val) {
  const has = current.permissions.includes(name)
  if (val && !has) current.permissions.push(name)
  else if (!val && has) current.permissions = current.permissions.filter(n => n !== name)
}

function toggleGroup (group, val) {
  const names = groupPermNames(group)
  if (val) {
    const set = new Set(current.permissions)
    names.forEach(n => set.add(n))
    current.permissions = Array.from(set)
  } else {
    current.permissions = current.permissions.filter(n => !names.includes(n))
  }
}

function selectAll () {
  current.permissions = permissions.value.map(p => p.name)
}

async function loadPermissions () {
  const { data } = await api.get('/permissions')
  permissions.value = data
}

async function loadRole () {
  try {
    const { data } = await api.get('/roles')
    const row = data.find(r => String(r.id) === String(route.params.id))
    if (!row) {
      Notify.create({ type: 'negative', message: 'Role not found' })
      return router.push('/roles')
    }
    Object.assign(current, {
      name: row.name,
      permissions: (row.permissions || []).map(p => p.name)
    })
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Load failed' })
  }
}

async function save () {
  if (!current.name) return Notify.create({ type: 'warning', message: 'Name is required' })
  saving.value = true
  try {
    const payload = { name: current.name, permissions: current.permissions }
    if (editing.value) await api.put(`/roles/${route.params.id}`, payload)
    else await api.post('/roles', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved successfully' })
    router.push('/roles')
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally {
    saving.value = false
  }
}

function goBack () { router.push('/roles') }

onMounted(async () => {
  await loadPermissions()
  if (editing.value) await loadRole()
})
</script>

<style scoped>
.perm-header {
  position: sticky;
  top: 0;
  z-index: 1;
  border-bottom: 1px solid var(--surface-border);
}
.role-save-banner {
  position: sticky;
  bottom: 0;
  background: var(--surface-card);
  border-top: 1px solid var(--surface-border);
  border-radius: 0 0 10px 10px;
  margin-top: 8px;
  z-index: 5;
}
</style>
