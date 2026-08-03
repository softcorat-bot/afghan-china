<template>
  <div class="q-pa-md">
    <div class="row items-center justify-between q-mb-lg">
      <h1 class="text-h4 q-ma-none">{{ $t('HardwareDevices') }}</h1>
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="add"
        :label="$t('AddNew')"
        @click="showAddModal = true"
      />
    </div>

    <!-- Filters -->
    <div class="row gap-md q-mb-lg">
      <q-select
        outlined
        dense
        :options="deviceTypes"
        option-value="value"
        option-label="label"
        emit-value
        map-options
        v-model="filterType"
        :label="$t('DeviceType')"
        class="col-auto"
        clearable
        style="min-width: 150px"
      />
    </div>

    <!-- Devices Table -->
    <q-table
      :rows="devices"
      :columns="columns"
      row-key="id"
      flat
      bordered
      :loading="loading"
      :pagination.sync="pagination"
      @request="onRequest"
    >
      <template v-slot:body-cell-status="props">
        <q-td :props="props">
          <q-chip
            :color="props.row.active ? 'green' : 'red'"
            text-color="white"
            size="sm"
            :label="props.row.active ? $t('Active') : $t('Inactive')"
          />
        </q-td>
      </template>

      <template v-slot:body-cell-last_sync="props">
        <q-td :props="props">
          {{ props.row.last_sync ? fmtDate(props.row.last_sync) : '—' }}
        </q-td>
      </template>

      <template v-slot:body-cell-actions="props">
        <q-td :props="props">
          <q-btn
            flat
            dense
            round
            icon="sync"
            size="sm"
            @click="syncDevice(props.row)"
            :loading="syncing === props.row.id"
          >
            <q-tooltip>{{ $t('Sync') }}</q-tooltip>
          </q-btn>
          <q-btn
            flat
            dense
            round
            icon="link_off"
            size="sm"
            @click="testConnection(props.row)"
            :loading="testing === props.row.id"
          >
            <q-tooltip>{{ $t('TestConnection') }}</q-tooltip>
          </q-btn>
          <q-btn
            flat
            dense
            round
            icon="edit"
            size="sm"
            @click="editDevice(props.row)"
          >
            <q-tooltip>{{ $t('Edit') }}</q-tooltip>
          </q-btn>
          <q-btn
            flat
            dense
            round
            icon="delete"
            size="sm"
            @click="deleteDevice(props.row)"
            color="negative"
          >
            <q-tooltip>{{ $t('Delete') }}</q-tooltip>
          </q-btn>
        </q-td>
      </template>
    </q-table>

    <!-- Add/Edit Modal -->
    <q-dialog v-model="showAddModal">
      <q-card style="min-width: 500px">
        <q-card-section class="row items-center q-pb-none">
          <div class="text-h6">{{ editingDevice ? $t('EditDevice') : $t('AddDevice') }}</div>
          <q-space />
          <q-btn icon="close" flat round dense v-close-popup />
        </q-card-section>

        <q-separator />

        <q-card-section>
          <form @submit.prevent="saveDevice" class="q-gutter-md">
            <q-input
              outlined
              dense
              v-model="form.device_id"
              :label="$t('DeviceId')"
              hint="MAC address or serial number"
              :rules="[val => !!val || $t('FieldIsRequired')]"
            />

            <q-select
              outlined
              dense
              v-model="form.device_type"
              :options="deviceTypes"
              option-value="value"
              option-label="label"
              emit-value
              map-options
              :label="$t('DeviceType')"
              :rules="[val => !!val || $t('FieldIsRequired')]"
            />

            <q-input
              outlined
              dense
              v-model="form.device_name"
              :label="$t('DeviceName')"
              :rules="[val => !!val || $t('FieldIsRequired')]"
            />

            <q-input
              outlined
              dense
              v-model="form.location"
              :label="$t('Location')"
              :rules="[val => !!val || $t('FieldIsRequired')]"
            />

            <q-input
              outlined
              dense
              v-model="form.ip_address"
              :label="$t('IpAddress')"
              type="text"
              :rules="[val => !!val || $t('FieldIsRequired')]"
            />

            <q-input
              outlined
              dense
              v-model="form.api_key"
              :label="$t('ApiKey')"
              type="password"
              hint="Leave empty to keep current"
            />

            <q-select
              outlined
              dense
              v-model="form.branch_id"
              :options="branches"
              option-value="id"
              option-label="name"
              emit-value
              map-options
              :label="$t('Branch')"
              clearable
            />

            <q-input
              outlined
              dense
              v-model="form.notes"
              :label="$t('Notes')"
              type="textarea"
            />

            <div class="row gap-sm justify-end">
              <q-btn
                flat
                no-caps
                :label="$t('Cancel')"
                v-close-popup
              />
              <q-btn
                unelevated
                no-caps
                color="primary"
                :label="$t('Save')"
                type="submit"
                :loading="saving"
              />
            </div>
          </form>
        </q-card-section>
      </q-card>
    </q-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/boot/axios'
import { useQuasar } from 'quasar'

const $q = useQuasar()
const devices = ref([])
const branches = ref([])
const loading = ref(false)
const saving = ref(false)
const syncing = ref(null)
const testing = ref(null)
const showAddModal = ref(false)
const editingDevice = ref(null)
const filterType = ref('')

const deviceTypes = [
  { label: 'Biometric', value: 'biometric' },
  { label: 'RFID', value: 'rfid' },
  { label: 'QR Code', value: 'qr_code' },
  { label: 'Manual', value: 'manual' },
]

const pagination = ref({
  sortBy: 'created_at',
  descending: true,
  page: 1,
  rowsPerPage: 15,
  rowsNumber: 0,
})

const columns = [
  { name: 'device_name', label: 'Device Name', field: 'device_name', align: 'left' },
  { name: 'device_type', label: 'Type', field: 'device_type', align: 'left' },
  { name: 'ip_address', label: 'IP Address', field: 'ip_address', align: 'left' },
  { name: 'location', label: 'Location', field: 'location', align: 'left' },
  { name: 'status', label: 'Status', field: 'active', align: 'left' },
  { name: 'last_sync', label: 'Last Sync', field: 'last_sync', align: 'left' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'center' },
]

const form = ref({
  device_id: '',
  device_type: '',
  device_name: '',
  location: '',
  ip_address: '',
  api_key: '',
  branch_id: null,
  notes: '',
})

const fmtDate = (v) => v ? new Date(v).toLocaleString() : '—'

async function loadDevices() {
  loading.value = true
  try {
    const { data } = await api.get('/hardware-devices', {
      params: {
        type: filterType.value || undefined,
        page: pagination.value.page,
        per_page: pagination.value.rowsPerPage,
      },
    })
    devices.value = data.devices.data
    pagination.value.rowsNumber = data.devices.total
  } catch (e) {
    $q.notify({ type: 'negative', message: 'Failed to load devices' })
  } finally {
    loading.value = false
  }
}

async function loadBranches() {
  try {
    const { data } = await api.get('/branches')
    branches.value = data.branches || []
  } catch (_) {}
}

async function saveDevice() {
  saving.value = true
  try {
    if (editingDevice.value) {
      await api.put(`/hardware-devices/${editingDevice.value.id}`, form.value)
      $q.notify({ type: 'positive', message: 'Device updated' })
    } else {
      await api.post('/hardware-devices', form.value)
      $q.notify({ type: 'positive', message: 'Device added' })
    }
    showAddModal.value = false
    resetForm()
    loadDevices()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Save failed' })
  } finally {
    saving.value = false
  }
}

async function testConnection(device) {
  testing.value = device.id
  try {
    const { data } = await api.post(`/hardware-devices/${device.id}/test-connection`)
    $q.notify({ type: 'positive', message: 'Connection successful!' })
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Connection failed' })
  } finally {
    testing.value = null
  }
}

async function syncDevice(device) {
  syncing.value = device.id
  try {
    const { data } = await api.post(`/hardware-devices/${device.id}/sync-records`)
    $q.notify({ type: 'positive', message: `Synced ${data.records_synced} records` })
    loadDevices()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Sync failed' })
  } finally {
    syncing.value = null
  }
}

async function deleteDevice(device) {
  $q.dialog({
    title: 'Delete Device',
    message: `Delete "${device.device_name}"?`,
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      await api.delete(`/hardware-devices/${device.id}`)
      $q.notify({ type: 'positive', message: 'Device deleted' })
      loadDevices()
    } catch (e) {
      $q.notify({ type: 'negative', message: 'Delete failed' })
    }
  })
}

function editDevice(device) {
  editingDevice.value = device
  form.value = {
    device_id: device.device_id,
    device_type: device.device_type,
    device_name: device.device_name,
    location: device.location,
    ip_address: device.ip_address,
    api_key: '',
    branch_id: device.branch_id,
    notes: device.notes,
  }
  showAddModal.value = true
}

function resetForm() {
  editingDevice.value = null
  form.value = {
    device_id: '',
    device_type: '',
    device_name: '',
    location: '',
    ip_address: '',
    api_key: '',
    branch_id: null,
    notes: '',
  }
}

function onRequest(props) {
  const { page, rowsPerPage } = props.pagination
  pagination.value.page = page
  pagination.value.rowsPerPage = rowsPerPage
  loadDevices()
}

onMounted(() => {
  loadDevices()
  loadBranches()
})
</script>
