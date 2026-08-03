<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="category" controlRoomButton="false" class="q-mt-xs">{{ $t('ProductCategories') }}</m-header>
        </div>

        <div class="col-12 col-sm-4 col-md-3">
          <stat-card icon="category" :label="$t('TotalCategories')" :value="rows.length" color="#0097a7" tint="#e0f7fa" />
        </div>

        <action-bar :rows="rows" :columns="columns" filename="product-categories" create-perm="category-create" @add="openCreate" @update:filtered="filteredRows = $event" />

        <div class="col-12">
          <n-table :loading="loading" :data="rows" :columns="columns" v-model:filter="filter"
            :can_edit="'product-category-edit'" :can_delete="'product-category-delete'" @edit="openEdit" @del="remove">
            <template v-slot:body-cell-name="props">
              <q-td :props="props">
                <div class="text-weight-medium">{{ props.row.name }}</div>
                <div v-if="props.row.name_fa" class="text-grey-6" style="font-size:11px">{{ props.row.name_fa }}</div>
              </q-td>
            </template>
            <template v-slot:body-cell-parent="props">
              <q-td :props="props">
                <span v-if="parentName(props.row.parent_id)">{{ parentName(props.row.parent_id) }}</span>
                <span v-else class="text-grey-5">—</span>
              </q-td>
            </template>
            <template v-slot:body-cell-products_count="props">
              <q-td :props="props">
                <q-badge color="primary" :label="props.row.products_count || 0" />
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

    <!-- Add / edit category (modal) -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 480px">
      <q-card class="bg-white">
        <n-header icon="category">{{ form.id ? $t('Edit') : $t('AddNew') }} — {{ $t('ProductCategory') }}</n-header>
        <q-separator />
        <q-form @submit="save">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 col-sm-6"><n-name :name="form.name" @update:name="form.name = $event" icon="category" :label="$t('Name')" autofocus /></div>
            <div class="col-12 col-sm-6"><n-name :name="form.name_fa" @update:name="form.name_fa = $event" icon="translate" :label="$t('NameFa')" :rules="[]" /></div>
            <div class="col-12">
              <q-select outlined dense color="primary" v-model="form.parent_id" :options="parentOptions" emit-value map-options clearable :label="$t('ParentCategory')">
                <template #prepend><q-icon name="account_tree" color="primary" /></template>
              </q-select>
            </div>
            <div class="col-12 col-sm-6">
              <q-input outlined dense color="primary" type="number" v-model.number="form.sort" :label="$t('Sort')">
                <template #prepend><q-icon name="sort" color="primary" /></template>
              </q-input>
            </div>
            <div class="col-12 col-sm-6 flex items-center">
              <q-toggle v-model="form.active" :label="$t('Active')" color="primary" />
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
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()

const rows = ref([])
const filteredRows = ref([])
const loading = ref(false)
const saving = ref(false)
const filter = ref('')
const dialog = ref(false)

const columns = [
  { name: 'created_at', label: '#', field: 'id', align: 'left' },
  { name: 'name', label: 'Name', field: 'name', align: 'left', sortable: true },
  { name: 'parent', label: 'ParentCategory', field: 'parent_id', align: 'left' },
  { name: 'products_count', label: 'Products', field: 'products_count', align: 'center', sortable: true },
  { name: 'active', label: 'Status', field: 'active', align: 'center' },
  { name: 'sort', label: 'Sort', field: 'sort', align: 'center', sortable: true },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'right' }
]

const blank = () => ({ id: null, name: '', name_fa: '', parent_id: null, active: true, sort: 0 })
const form = reactive(blank())

function parentName (parentId) {
  if (!parentId) return ''
  const p = rows.value.find(x => x.id === parentId)
  return p ? p.name : ''
}

const parentOptions = computed(() =>
  rows.value
    .filter(c => c.id !== form.id)
    .map(c => ({ label: c.name, value: c.id }))
)

async function load () {
  loading.value = true
  try { const { data } = await api.get('/product-categories'); rows.value = data } finally { loading.value = false }
}

function openCreate () { Object.assign(form, blank()); dialog.value = true }
function openEdit (id) {
  const r = rows.value.find(x => x.id === id); if (!r) return
  Object.assign(form, { id: r.id, name: r.name, name_fa: r.name_fa || '', parent_id: r.parent_id || null, active: !!r.active, sort: r.sort || 0 })
  dialog.value = true
}

async function save () {
  if (!form.name) { Notify.create({ type: 'negative', message: proxy.$t('NameRequired') }); return }
  saving.value = true
  try {
    const payload = { name: form.name, name_fa: form.name_fa, parent_id: form.parent_id, active: form.active, sort: Number(form.sort) || 0 }
    if (form.id) await api.put('/product-categories/' + form.id, payload)
    else await api.post('/product-categories', payload)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: proxy.$t('Saved') })
    dialog.value = false; load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || proxy.$t('SaveFailed') })
  } finally { saving.value = false }
}

function remove (id) { proxy.$delete('product-categories/' + id, load) }

onMounted(load)
</script>
