<template>
  <div class="q-pa-md">
    <div class="row items-center justify-between q-mb-lg">
      <h1 class="text-h4 q-ma-none">{{ $t('SyncMonitor') }}</h1>
      <div class="row gap-sm">
        <q-select
          v-model="days"
          outlined dense
          :options="[7, 14, 30]"
          :label="$t('Days')"
          class="col-auto"
          style="min-width: 110px"
          @update:model-value="load"
        />
        <q-btn flat no-caps icon="refresh" :label="$t('Refresh')" :loading="loading" @click="load" />
        <q-btn
          v-if="$can('manage-devices')"
          flat no-caps color="grey-8" icon="cleaning_services"
          :label="$t('PruneHistory')"
          @click="prune"
        />
      </div>
    </div>

    <!-- Totals -->
    <div class="row gap-md q-mb-lg" v-if="overview">
      <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
        <div class="text-caption text-grey-7">{{ $t('OnlineNow') }}</div>
        <div class="text-h5">{{ overview.totals.online_recently }} / {{ overview.totals.devices }}</div>
      </q-card>
      <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
        <div class="text-caption text-grey-7">{{ $t('Uploaded24h') }}</div>
        <div class="text-h5 text-green">{{ overview.totals.records_uploaded_24h }}</div>
      </q-card>
      <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
        <div class="text-caption text-grey-7">{{ $t('Downloaded24h') }}</div>
        <div class="text-h5 text-blue">{{ overview.totals.records_downloaded_24h }}</div>
      </q-card>
      <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
        <div class="text-caption text-grey-7">{{ $t('PendingConflicts') }}</div>
        <div class="text-h5" :class="overview.totals.pending_conflicts ? 'text-deep-orange' : 'text-green'">
          {{ overview.totals.pending_conflicts }}
          <span v-if="overview.totals.critical_conflicts" class="text-caption text-red">({{ overview.totals.critical_conflicts }} {{ $t('Critical') }})</span>
        </div>
      </q-card>
      <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
        <div class="text-caption text-grey-7">{{ $t('FailedBatches7d') }}</div>
        <div class="text-h5" :class="overview.totals.failed_batches_7d ? 'text-red' : 'text-green'">
          {{ overview.totals.failed_batches_7d }}
        </div>
      </q-card>
      <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
        <div class="text-caption text-grey-7">{{ $t('ServerPosition') }}</div>
        <div class="text-h5">#{{ overview.server_seq }}</div>
        <div class="text-caption text-grey-7">{{ fmtDateTime(overview.server_time) }}</div>
      </q-card>
    </div>

    <!-- Devices -->
    <div class="text-h6 q-mb-sm">{{ $t('Devices') }}</div>
    <q-table
      :rows="overview?.devices || []"
      :columns="deviceColumns"
      row-key="device_id"
      flat bordered dense
      :loading="loading"
      :pagination="{ rowsPerPage: 10 }"
      class="q-mb-xl"
      :row-class="row => row.needs_attention ? 'bg-red-1' : ''"
    >
      <template v-slot:body-cell-attention="props">
        <q-td :props="props">
          <q-icon v-if="props.row.needs_attention" name="warning" color="red">
            <q-tooltip>{{ $t('NeedsAttention') }}</q-tooltip>
          </q-icon>
          <q-icon v-else name="check_circle" color="green" />
        </q-td>
      </template>
      <template v-slot:body-cell-status="props">
        <q-td :props="props">
          <q-chip
            :color="{ pending: 'amber-9', active: 'green', disabled: 'grey-7', revoked: 'red' }[props.row.status] || 'grey'"
            text-color="white" size="sm"
            :label="$t('DeviceStatus_' + props.row.status)"
          />
        </q-td>
      </template>
      <template v-slot:body-cell-last_seen_at="props">
        <q-td :props="props">{{ fmtDateTime(props.row.last_seen_at) }}</q-td>
      </template>
      <template v-slot:body-cell-flow="props">
        <q-td :props="props">
          <q-icon name="upload" size="xs" color="green" /> {{ props.row.uploads_7d }}
          <q-icon name="download" size="xs" color="blue" class="q-ml-sm" /> {{ props.row.downloads_7d }}
          <span v-if="props.row.pending_on_device" class="q-ml-sm text-amber-9">⏳ {{ props.row.pending_on_device }}</span>
          <span v-if="props.row.failed_on_device" class="q-ml-sm text-red">⚠ {{ props.row.failed_on_device }}</span>
        </q-td>
      </template>
    </q-table>

    <!-- Batches -->
    <div class="row items-center gap-md q-mb-sm">
      <div class="text-h6">{{ $t('BatchHistory') }}</div>
      <q-select
        v-model="batchDirection"
        outlined dense
        :options="[{ label: 'push', value: 'push' }, { label: 'pull', value: 'pull' }]"
        option-value="value" option-label="label" emit-value map-options
        :label="$t('Direction')" clearable style="min-width: 130px"
        @update:model-value="load"
      />
      <q-select
        v-model="batchStatus"
        outlined dense
        :options="['completed', 'partial', 'failed']"
        :label="$t('Status')" clearable style="min-width: 130px"
        @update:model-value="load"
      />
    </div>

    <q-table
      :rows="overview?.batches || []"
      :columns="batchColumns"
      row-key="id"
      flat bordered dense
      :loading="loading"
      :pagination="{ rowsPerPage: 15 }"
    >
      <template v-slot:body-cell-direction="props">
        <q-td :props="props">
          <q-chip size="sm" :color="props.row.direction === 'push' ? 'green-1' : 'blue-1'" :text-color="props.row.direction === 'push' ? 'green-10' : 'blue-10'">
            <q-icon :name="props.row.direction === 'push' ? 'upload' : 'download'" size="xs" /> {{ props.row.direction }}
          </q-chip>
        </q-td>
      </template>
      <template v-slot:body-cell-status="props">
        <q-td :props="props">
          <q-chip size="sm" :color="props.row.status === 'completed' ? 'green' : (props.row.status === 'partial' ? 'amber' : 'red')" text-color="white" :label="props.row.status" />
          <q-tooltip v-if="props.row.error_message">{{ props.row.error_message }}</q-tooltip>
        </q-td>
      </template>
      <template v-slot:body-cell-created_at="props">
        <q-td :props="props">{{ fmtDateTime(props.row.created_at) }}</q-td>
      </template>
    </q-table>

    <!-- Recent errors -->
    <template v-if="overview?.recent_errors?.length">
      <div class="text-h6 q-mt-xl q-mb-sm">{{ $t('RecentErrors') }}</div>
      <q-list bordered separator dense class="rounded-borders">
        <q-item v-for="err in overview.recent_errors" :key="err.id">
          <q-item-section avatar><q-icon name="error" color="red" /></q-item-section>
          <q-item-section>
            <q-item-label>{{ err.error_message }}</q-item-label>
            <q-item-label caption>{{ err.device_id }} · {{ err.direction }} · {{ fmtDateTime(err.created_at) }}</q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
    </template>
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

const overview = ref(null)
const loading = ref(false)
const days = ref(7)
const batchDirection = ref(null)
const batchStatus = ref(null)

const deviceColumns = [
  { name: 'attention', label: '', field: 'needs_attention', align: 'center' },
  { name: 'name', label: 'Device', field: 'name', align: 'left' },
  { name: 'branch', label: 'Branch', field: 'branch', align: 'left' },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
  { name: 'last_seen_at', label: 'Last seen', field: 'last_seen_at', align: 'left' },
  { name: 'flow', label: '7 days ↑↓', field: 'uploads_7d', align: 'left' },
  { name: 'app_version', label: 'App', field: 'app_version', align: 'left' },
]

const batchColumns = [
  { name: 'device_id', label: 'Device', field: 'device_id', align: 'left' },
  { name: 'direction', label: 'Direction', field: 'direction', align: 'left' },
  { name: 'status', label: 'Status', field: 'status', align: 'left' },
  { name: 'changes_received', label: 'Received', field: 'changes_received', align: 'right' },
  { name: 'applied', label: 'Applied', field: 'applied', align: 'right' },
  { name: 'duplicates', label: 'Duplicates', field: 'duplicates', align: 'right' },
  { name: 'conflicts', label: 'Conflicts', field: 'conflicts', align: 'right' },
  { name: 'rejected', label: 'Rejected', field: 'rejected', align: 'right' },
  { name: 'rows_sent', label: 'Rows', field: 'rows_sent', align: 'right' },
  { name: 'created_at', label: 'Date', field: 'created_at', align: 'left' },
]

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/sync/overview', {
      params: {
        days: days.value,
        direction: batchDirection.value || undefined,
        status: batchStatus.value || undefined,
      },
    })
    overview.value = data
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    loading.value = false
  }
}

function prune() {
  $q.dialog({
    title: t('PruneHistory'),
    message: t('PruneSyncConfirm'),
    prompt: { model: 120, type: 'number', min: 30 },
    cancel: true,
    persistent: true,
  }).onOk(async (pruneDays) => {
    try {
      const { data } = await api.post('/sync/prune', { days: Number(pruneDays) || 120 })
      $q.notify({ type: 'positive', message: `${t('Pruned')}: ${data.pruned}` })
      load()
    } catch (e) {
      $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
    }
  })
}

onMounted(load)
</script>
