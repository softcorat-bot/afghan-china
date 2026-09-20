<template>
  <div class="q-pa-md">
    <div class="row items-center justify-between q-mb-lg">
      <h1 class="text-h4 q-ma-none">{{ $t('PosDevices') }}</h1>
      <div class="row gap-sm">
        <q-btn flat no-caps icon="refresh" :label="$t('Refresh')" :loading="loading" @click="loadDevices" />
        <q-btn
          v-if="$can('manage-devices')"
          unelevated
          no-caps
          color="primary"
          icon="add"
          :label="$t('RegisterNewDevice')"
          @click="showRegister = true"
        />
      </div>
    </div>

    <!-- Fleet summary -->
    <div class="row gap-md q-mb-lg" v-if="summary">
      <q-chip square color="blue-1" text-color="blue-10" icon="devices">
        {{ $t('Devices') }}: {{ summary.total }}
      </q-chip>
      <q-chip square color="green-1" text-color="green-10" icon="check_circle">
        {{ $t('Active') }}: {{ summary.active }}
      </q-chip>
      <q-chip square color="amber-1" text-color="amber-10" icon="hourglass_top">
        {{ $t('Pending') }}: {{ summary.pending }}
      </q-chip>
      <q-chip square color="grey-3" text-color="grey-9" icon="block">
        {{ $t('Disabled') }}: {{ summary.disabled }}
      </q-chip>
      <q-chip square color="red-1" text-color="red-10" icon="gpp_bad">
        {{ $t('Revoked') }}: {{ summary.revoked }}
      </q-chip>
      <q-chip
        square
        :color="summary.pending_conflicts ? 'red-1' : 'green-1'"
        :text-color="summary.pending_conflicts ? 'red-10' : 'green-10'"
        icon="sync_problem"
      >
        {{ $t('PendingConflicts') }}: {{ summary.pending_conflicts }}
      </q-chip>
    </div>

    <!-- Filters -->
    <div class="row gap-md q-mb-lg">
      <q-input
        v-model="search"
        outlined
        dense
        debounce="400"
        :label="$t('Search')"
        class="col-auto"
        style="min-width: 260px"
        clearable
        @update:model-value="loadDevices"
      >
        <template v-slot:prepend><q-icon name="search" /></template>
      </q-input>
      <q-select
        v-model="filterStatus"
        outlined
        dense
        :options="statusOptions"
        option-value="value"
        option-label="label"
        emit-value
        map-options
        :label="$t('Status')"
        class="col-auto"
        style="min-width: 160px"
        clearable
        @update:model-value="loadDevices"
      />
    </div>

    <q-table
      :rows="devices"
      :columns="columns"
      row-key="id"
      flat
      bordered
      :loading="loading"
      :pagination="{ rowsPerPage: 25 }"
      :no-data-label="$t('NoDevicesYet')"
    >
      <template v-slot:body-cell-name="props">
        <q-td :props="props">
          <div class="text-weight-medium">{{ props.row.name || '—' }}</div>
          <div class="text-caption text-grey-7">{{ props.row.device_id }}</div>
        </q-td>
      </template>

      <template v-slot:body-cell-branch="props">
        <q-td :props="props">{{ props.row.branch?.name || '—' }}</q-td>
      </template>

      <template v-slot:body-cell-status="props">
        <q-td :props="props">
          <q-chip
            :color="statusColor(props.row.status)"
            text-color="white"
            size="sm"
            :label="$t('DeviceStatus_' + props.row.status)"
          />
          <q-tooltip v-if="props.row.is_stale" :delay="200">{{ $t('StaleDevice') }}</q-tooltip>
          <q-icon v-if="props.row.is_stale" name="schedule" color="amber" class="q-ml-xs">
            <q-tooltip>{{ $t('StaleDevice') }}</q-tooltip>
          </q-icon>
        </q-td>
      </template>

      <template v-slot:body-cell-last_seen_at="props">
        <q-td :props="props">{{ fmtDateTime(props.row.last_seen_at) }}</q-td>
      </template>

      <template v-slot:body-cell-last_sync_at="props">
        <q-td :props="props">{{ fmtDateTime(props.row.last_sync_at) }}</q-td>
      </template>

      <template v-slot:body-cell-holding="props">
        <q-td :props="props">
          <q-badge v-if="props.row.pending_count" color="amber" text-color="black" class="q-mr-xs">
            {{ props.row.pending_count }} {{ $t('Pending') }}
          </q-badge>
          <q-badge v-if="props.row.failed_count" color="red">
            {{ props.row.failed_count }} {{ $t('Failed') }}
          </q-badge>
          <q-badge v-if="props.row.pending_conflicts" color="deep-orange" class="q-ml-xs">
            {{ props.row.pending_conflicts }} {{ $t('Conflicts') }}
          </q-badge>
          <span v-if="!props.row.pending_count && !props.row.failed_count && !props.row.pending_conflicts" class="text-green">
            ✓
          </span>
        </q-td>
      </template>

      <template v-slot:body-cell-actions="props">
        <q-td :props="props">
          <q-btn flat dense round icon="visibility" size="sm" @click="openDetail(props.row)">
            <q-tooltip>{{ $t('Details') }}</q-tooltip>
          </q-btn>

          <q-btn
            v-if="props.row.status === 'pending' && $can('manage-devices')"
            flat dense round icon="vpn_key" size="sm" color="amber-9"
            @click="reauthorize(props.row)"
          >
            <q-tooltip>{{ $t('Reauthorize') }}</q-tooltip>
          </q-btn>

          <q-btn
            v-if="props.row.status !== 'active' && props.row.status !== 'revoked' && $can('manage-devices')"
            flat dense round icon="play_circle" size="sm" color="green"
            @click="enable(props.row)"
          >
            <q-tooltip>{{ $t('Enable') }}</q-tooltip>
          </q-btn>

          <q-btn
            v-if="props.row.status === 'active' && $can('manage-devices')"
            flat dense round icon="pause_circle" size="sm" color="amber-9"
            @click="disable(props.row)"
          >
            <q-tooltip>{{ $t('Disable') }}</q-tooltip>
          </q-btn>

          <q-btn
            v-if="props.row.status !== 'revoked' && $can('manage-devices')"
            flat dense round icon="gpp_bad" size="sm" color="deep-orange"
            @click="revoke(props.row)"
          >
            <q-tooltip>{{ $t('Revoke') }}</q-tooltip>
          </q-btn>

          <q-btn
            v-if="$can('manage-devices')"
            flat dense round icon="delete" size="sm" color="negative"
            @click="remove(props.row)"
          >
            <q-tooltip>{{ $t('Delete') }}</q-tooltip>
          </q-btn>
        </q-td>
      </template>
    </q-table>

    <!-- Register dialog -->
    <q-dialog v-model="showRegister" persistent>
      <q-card style="min-width: 480px">
        <q-card-section class="row items-center q-pb-none">
          <div class="text-h6">{{ $t('RegisterNewDevice') }}</div>
          <q-space />
          <q-btn icon="close" flat round dense v-close-popup />
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div class="text-body2 text-grey-8 q-mb-md">{{ $t('RegisterDeviceHint') }}</div>
          <form @submit.prevent="registerDevice" class="q-gutter-md">
            <q-input
              outlined dense v-model="form.name" :label="$t('DeviceName')"
              :rules="[val => !!val || $t('FieldIsRequired')]"
            />
            <q-input
              outlined dense v-model="form.device_id" :label="$t('DeviceId')"
              :hint="$t('DeviceIdHint')"
            />
            <q-select
              outlined dense v-model="form.branch_id" :options="branches"
              option-value="id" option-label="name" emit-value map-options
              :label="$t('Branch')" clearable
            />
            <q-input
              outlined dense v-model.number="form.activation_days" type="number"
              :label="$t('ActivationCodeValidDays')" :min="1" :max="90"
            />
            <q-input outlined dense v-model="form.notes" type="textarea" :label="$t('Notes')" />
            <div class="row gap-sm justify-end">
              <q-btn flat no-caps :label="$t('Cancel')" v-close-popup />
              <q-btn unelevated no-caps color="primary" :label="$t('Save')" type="submit" :loading="saving" />
            </div>
          </form>
        </q-card-section>
      </q-card>
    </q-dialog>

    <!-- Activation code (shown exactly once) -->
    <q-dialog v-model="showActivation" persistent>
      <q-card style="min-width: 480px">
        <q-card-section class="row items-center q-pb-none">
          <div class="text-h6">{{ $t('ActivationCode') }}</div>
          <q-space />
          <q-btn icon="close" flat round dense @click="showActivation = false" />
        </q-card-section>
        <q-separator />
        <q-card-section class="text-center">
          <div class="text-h3 text-weight-bold text-primary q-my-md" style="letter-spacing: 0.15em">
            {{ activation.code }}
          </div>
          <div class="text-body2 text-grey-8">{{ $t('ActivationCodeShownOnce') }}</div>
          <div class="text-caption text-grey-7 q-mt-sm" v-if="activation.expires_at">
            {{ $t('ExpiresAt') }}: {{ fmtDateTime(activation.expires_at) }}
          </div>
        </q-card-section>
        <q-card-actions align="center" class="q-pb-md">
          <q-btn unelevated no-caps color="primary" icon="content_copy" :label="$t('Copy')" @click="copyCode" />
          <q-btn flat no-caps :label="$t('Close')" @click="showActivation = false" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Detail drawer -->
    <q-dialog v-model="showDetail" position="right" full-height>
      <q-card style="width: 560px; max-width: 95vw">
        <q-card-section class="row items-center q-pb-none">
          <div class="text-h6">{{ detail.device?.name || detail.device?.device_id }}</div>
          <q-space />
          <q-btn icon="close" flat round dense v-close-popup />
        </q-card-section>
        <q-separator />
        <q-card-section v-if="detail.device" class="q-gutter-md">
          <q-list dense bordered separator class="rounded-borders">
            <q-item><q-item-section>{{ $t('DeviceId') }}</q-item-section><q-item-section side>{{ detail.device.device_id }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('Status') }}</q-item-section><q-item-section side>
              <q-chip :color="statusColor(detail.device.status)" text-color="white" size="sm" :label="$t('DeviceStatus_' + detail.device.status)" />
            </q-item-section></q-item>
            <q-item><q-item-section>{{ $t('Branch') }}</q-item-section><q-item-section side>{{ detail.device.branch?.name || '—' }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('LastSeen') }}</q-item-section><q-item-section side>{{ fmtDateTime(detail.device.last_seen_at) }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('LastSync') }}</q-item-section><q-item-section side>{{ fmtDateTime(detail.device.last_sync_at) }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('AppVersion') }}</q-item-section><q-item-section side>{{ detail.device.app_version || '—' }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('PendingOnDevice') }}</q-item-section><q-item-section side>{{ detail.device.pending_count ?? 0 }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('FailedOnDevice') }}</q-item-section><q-item-section side>{{ detail.device.failed_count ?? 0 }}</q-item-section></q-item>
            <q-item v-if="detail.device.notes"><q-item-section>{{ $t('Notes') }}</q-item-section><q-item-section side>{{ detail.device.notes }}</q-item-section></q-item>
            <q-item v-if="detail.device.revoked_reason"><q-item-section>{{ $t('Reason') }}</q-item-section><q-item-section side>{{ detail.device.revoked_reason }}</q-item-section></q-item>
            <q-item v-if="detail.device.last_error"><q-item-section>{{ $t('ErrorMessage') }}</q-item-section><q-item-section side>{{ detail.device.last_error.message || detail.device.last_error }}</q-item-section></q-item>
          </q-list>

          <div>
            <div class="text-subtitle1 q-mb-sm">{{ $t('BatchHistory') }}</div>
            <q-markup-table flat bordered dense v-if="detail.batches?.length">
              <thead>
                <tr>
                  <th class="text-left">{{ $t('Direction') }}</th>
                  <th class="text-left">{{ $t('Status') }}</th>
                  <th class="text-right">{{ $t('AppliedCount') }}</th>
                  <th class="text-left">{{ $t('Date') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="batch in detail.batches" :key="batch.id">
                  <td>
                    <q-icon :name="batch.direction === 'push' ? 'upload' : 'download'" size="xs" />
                    {{ batch.direction }}
                  </td>
                  <td>
                    <q-chip :color="batch.status === 'completed' ? 'green' : (batch.status === 'partial' ? 'amber' : 'red')" text-color="white" size="sm" :label="batch.status" />
                  </td>
                  <td class="text-right">{{ batch.direction === 'push' ? batch.applied : batch.rows_sent }}</td>
                  <td>{{ fmtDateTime(batch.finished_at || batch.created_at) }}</td>
                </tr>
              </tbody>
            </q-markup-table>
            <div v-else class="text-grey-7">{{ $t('NoData') }}</div>
          </div>

          <div>
            <div class="text-subtitle1 q-mb-sm">{{ $t('Conflicts') }}</div>
            <q-list bordered separator dense class="rounded-borders" v-if="detail.conflicts?.length">
              <q-item v-for="conflict in detail.conflicts" :key="conflict.id">
                <q-item-section>
                  <q-item-label>{{ conflict.entity_type }} · {{ shortUuid(conflict.entity_uuid) }}</q-item-label>
                  <q-item-label caption>{{ fmtDateTime(conflict.detected_at) }} — {{ conflict.reason }}</q-item-label>
                </q-item-section>
                <q-item-section side>
                  <q-chip :color="conflict.status === 'pending' ? 'deep-orange' : 'green'" text-color="white" size="sm" :label="conflict.status" />
                </q-item-section>
              </q-item>
            </q-list>
            <div v-else class="text-grey-7">{{ $t('NoConflicts') }}</div>
          </div>
        </q-card-section>
      </q-card>
    </q-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/boot/axios'
import { useQuasar } from 'quasar'
import { i18n } from '@/boot/i18n'
import { fmtDateTime } from '@/utils/date'

const $q = useQuasar()
const t = i18n.t

const devices = ref([])
const summary = ref(null)
const branches = ref([])
const loading = ref(false)
const saving = ref(false)
const search = ref('')
const filterStatus = ref(null)

const showRegister = ref(false)
const showActivation = ref(false)
const showDetail = ref(false)
const activation = ref({ code: '', expires_at: null })
const detail = ref({ device: null, batches: [], conflicts: [] })

const form = ref({ name: '', device_id: '', branch_id: null, activation_days: 14, notes: '' })

const statusOptions = [
  { label: 'Pending', value: 'pending' },
  { label: 'Active', value: 'active' },
  { label: 'Disabled', value: 'disabled' },
  { label: 'Revoked', value: 'revoked' },
]

const columns = [
  { name: 'name', label: 'Device', field: 'name', align: 'left', sortable: true },
  { name: 'branch', label: 'Branch', field: 'branch', align: 'left' },
  { name: 'status', label: 'Status', field: 'status', align: 'left', sortable: true },
  { name: 'last_seen_at', label: 'Last seen', field: 'last_seen_at', align: 'left', sortable: true },
  { name: 'last_sync_at', label: 'Last sync', field: 'last_sync_at', align: 'left', sortable: true },
  { name: 'holding', label: 'Holding', field: 'pending_count', align: 'left' },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'center' },
]

function statusColor(status) {
  return { pending: 'amber-9', active: 'green', disabled: 'grey-7', revoked: 'red' }[status] || 'grey'
}

function shortUuid(value) {
  return value ? String(value).slice(0, 8) + '…' : '—'
}

async function loadDevices() {
  loading.value = true
  try {
    const { data } = await api.get('/devices', {
      params: { search: search.value || undefined, status: filterStatus.value || undefined },
    })
    devices.value = data.data || []
    summary.value = data.summary || null
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    loading.value = false
  }
}

async function loadBranches() {
  try {
    const { data } = await api.get('/branches')
    branches.value = data.branches || []
  } catch (_) { /* branch list is optional here */ }
}

async function registerDevice() {
  saving.value = true
  try {
    const payload = { ...form.value }
    if (!payload.device_id) delete payload.device_id
    if (!payload.branch_id) delete payload.branch_id
    if (!payload.notes) delete payload.notes
    const { data } = await api.post('/devices', payload)
    activation.value = { code: data.activation_code, expires_at: data.expires_at }
    showRegister.value = false
    showActivation.value = true
    form.value = { name: '', device_id: '', branch_id: null, activation_days: 14, notes: '' }
    $q.notify({ type: 'positive', message: data.instructions || 'Registered' })
    loadDevices()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Save failed' })
  } finally {
    saving.value = false
  }
}

async function reauthorize(device) {
  try {
    const { data } = await api.post(`/devices/${device.id}/reauthorize`)
    activation.value = { code: data.activation_code, expires_at: data.device?.activation_expires_at }
    showActivation.value = true
    loadDevices()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  }
}

async function enable(device) {
  try {
    const { data } = await api.post(`/devices/${device.id}/enable`)
    $q.notify({ type: 'positive', message: data.message || 'Enabled' })
    loadDevices()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  }
}

function disable(device) {
  $q.dialog({
    title: t('Disable'),
    message: t('DisableDeviceConfirm'),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      const { data } = await api.post(`/devices/${device.id}/disable`)
      $q.notify({ type: 'positive', message: data.message || 'Disabled' })
      loadDevices()
    } catch (e) {
      $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
    }
  })
}

function revoke(device) {
  $q.dialog({
    title: t('Revoke'),
    message: t('RevokeDeviceConfirm'),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      const { data } = await api.post(`/devices/${device.id}/revoke`)
      $q.notify({ type: 'positive', message: data.message || 'Revoked' })
      loadDevices()
    } catch (e) {
      $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
    }
  })
}

function remove(device) {
  $q.dialog({
    title: t('Delete'),
    message: t('DeleteDeviceConfirm'),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      await api.delete(`/devices/${device.id}`)
      $q.notify({ type: 'positive', message: t('Deleted') })
      loadDevices()
    } catch (e) {
      $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
    }
  })
}

async function openDetail(device) {
  detail.value = { device, batches: [], conflicts: [] }
  showDetail.value = true
  try {
    const { data } = await api.get(`/devices/${device.id}`)
    detail.value = data
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  }
}

async function copyCode() {
  try {
    await navigator.clipboard.writeText(activation.value.code)
    $q.notify({ type: 'positive', message: t('Copied') })
  } catch (_) {
    $q.notify({ type: 'warning', message: t('CopyFailed') })
  }
}

onMounted(() => {
  loadDevices()
  loadBranches()
})
</script>
