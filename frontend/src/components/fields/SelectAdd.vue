<template>
  <!--
    Searchable select for an admin-managed lookup, with an inline "＋ Add" that
    creates a new record without leaving the page (a small anchored popup — NOT a
    modal dialog). Self-loads its options from `endpoint`.

      <n-select-add v-model="form.department_id" endpoint="/departments"
                    :label="$t('Department')" icon="grid_view" />

    Richer lookups can describe their quick-add fields:
      :fields="[{ key:'name', labelKey:'Name', icon:'label' },
                { key:'phone', labelKey:'Phone', icon:'phone' }]"
  -->
  <q-select
    outlined dense
    :color="color" :label-color="color"
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    :options="filtered"
    :label="label"
    :loading="loading"
    emit-value map-options clearable
    use-input input-debounce="0" @filter="onFilter"
  >
    <template #prepend><q-icon :name="icon" :color="color" /></template>
    <template #no-option>
      <q-item><q-item-section class="text-grey">{{ $t('NoRecordFound') }}</q-item-section></q-item>
    </template>

    <!-- inline quick-add -->
    <template #after>
      <q-btn round dense flat :color="color" icon="add" type="button" @click.stop="adding = !adding">
        <q-tooltip>{{ $t('AddNew') }}</q-tooltip>
        <q-menu v-model="adding" no-parent-event anchor="bottom right" self="top right"
          @hide="draft = {}">
          <div class="q-pa-md column select-add-pop" style="min-width:280px" @click.stop>
            <div class="row items-center q-mb-sm">
              <q-icon :name="icon" :color="color" size="20px" class="q-mr-sm" />
              <div class="text-subtitle2 text-weight-bold">{{ $t('AddNew') }}</div>
              <q-space />
              <div class="text-caption text-grey-6 ellipsis" style="max-width:120px">{{ label }}</div>
            </div>
            <q-input
              v-for="(f, i) in fields" :key="f.key"
              outlined dense :color="color" :label-color="color"
              v-model="draft[f.key]" :type="f.type || 'text'"
              :label="f.label || $t(f.labelKey || 'Name')" :autofocus="i === 0"
              class="q-mb-sm" @keyup.enter="create"
            >
              <template #prepend><q-icon :name="f.icon || icon" :color="color" size="xs" /></template>
            </q-input>
            <div class="row justify-end q-gutter-sm q-mt-xs">
              <q-btn flat dense :label="$t('Cancel')" color="grey-7" type="button" v-close-popup />
              <q-btn unelevated dense :color="color" icon="save" :label="$t('Save')"
                type="button" @click="create" :loading="saving" />
            </div>
          </div>
        </q-menu>
      </q-btn>
    </template>
  </q-select>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/boot/axios'
import { Notify } from 'quasar'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  endpoint: { type: String, required: true },          // e.g. '/departments'
  label: { type: String, default: '' },
  icon: { type: String, default: 'list' },
  color: { type: String, default: 'primary' },
  optionLabel: { type: String, default: 'name' },      // record field shown in the list
  params: { type: Object, default: () => ({}) },       // query params for the GET (e.g. { type: 'customer' })
  createPayload: { type: Object, default: () => ({}) }, // fixed fields merged into the POST (e.g. { type: 'customer' })
  // quick-add form fields; default is a single Name field
  fields: { type: Array, default: () => [{ key: 'name', labelKey: 'Name', icon: 'label' }] },
})
const emit = defineEmits(['update:modelValue', 'created', 'loaded'])

const raw = ref([])
const options = ref([])
const loading = ref(false)
const adding = ref(false)
const saving = ref(false)
const draft = ref({})
const needle = ref('')

const filtered = computed(() => {
  if (!needle.value) return options.value
  const q = needle.value.toLowerCase()
  return options.value.filter((o) => String(o.label).toLowerCase().includes(q))
})

function toOptions(list) {
  return list.map((r) => ({ label: r[props.optionLabel] ?? r.name ?? ('#' + r.id), value: r.id }))
}

async function loadOptions() {
  loading.value = true
  try {
    const { data } = await api.get(props.endpoint, { params: props.params })
    raw.value = Array.isArray(data) ? data : (data.data ?? [])
    options.value = toOptions(raw.value)
    emit('loaded', raw.value)
  } finally {
    loading.value = false
  }
}

function onFilter(val, update) {
  update(() => { needle.value = val })
}

async function create() {
  saving.value = true
  try {
    const { data } = await api.post(props.endpoint, { ...props.createPayload, ...draft.value })
    await loadOptions()
    const id = data?.id ?? data?.data?.id
    if (id != null) emit('update:modelValue', id)
    emit('created', data)
    adding.value = false
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Added' })
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally {
    saving.value = false
  }
}

onMounted(loadOptions)
</script>
