<template>
  <div class="q-pa-md">
    <div class="row items-center justify-between q-mb-lg">
      <h1 class="text-h4 q-ma-none">{{ $t('SyncCenter') }}</h1>
      <div class="row gap-sm">
        <q-btn flat no-caps icon="refresh" :label="$t('Refresh')" :loading="loading" @click="load" />
        <q-btn
          v-if="isOffline && $can('sync-now')"
          unelevated no-caps color="primary" icon="sync"
          :label="$t('SyncNow')" :loading="syncing" :disable="!status?.device?.registered"
          @click="syncNow"
        />
      </div>
    </div>

    <!-- This page on an Online installation: nothing to sync, say so plainly -->
    <q-banner v-if="!loading && !isOffline" rounded class="bg-blue-1 q-mb-lg">
      <template v-slot:avatar><q-icon name="cloud_done" color="primary" /></template>
      {{ $t('SyncCenterOnlineNote') }}
    </q-banner>

    <template v-if="isOffline && status">
      <!-- Not registered yet: the activation step -->
      <q-card v-if="!status.device.registered" flat bordered class="q-pa-md q-mb-lg" style="max-width: 560px">
        <div class="text-h6 q-mb-xs">{{ $t('NotRegistered') }}</div>
        <div class="text-body2 text-grey-8 q-mb-md">{{ $t('NotRegisteredHint') }}</div>
        <q-input v-model="reg.device_id" outlined dense :label="$t('DeviceId')" class="q-mb-sm" hint="AC-KBL-XXXXXX" />
        <q-input v-model="reg.activation_code" outlined dense :label="$t('ActivationCode')" class="q-mb-md" />
        <q-btn
          v-if="$can('manage-devices')"
          unelevated no-caps color="primary" icon="key"
          :label="$t('Register')" :loading="registering"
          :disable="!reg.activation_code" @click="registerDevice"
        />
      </q-card>

      <!-- Status cards -->
      <div class="row gap-md q-mb-lg">
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
          <div class="text-caption text-grey-7">{{ $t('Connection') }}</div>
          <div class="text-h5" :class="connectionClass">
            <q-icon :name="connectionIcon" size="sm" />
            {{ connectionLabel }}
          </div>
          <div class="text-caption text-grey-7 ellipsis" style="max-width: 220px">{{ status.central_url || '—' }}</div>
        </q-card>
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 170px">
          <div class="text-caption text-grey-7">{{ $t('LastSync') }}</div>
          <div class="text-h6">{{ status.last_run_at ? fmtDateTime(status.last_run_at) : $t('Never') }}</div>
          <div class="text-caption text-grey-7">{{ $t('LastCleanSync') }}: {{ status.last_ok_at ? fmtDateTime(status.last_ok_at) : $t('Never') }}</div>
        </q-card>
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 130px">
          <div class="text-caption text-grey-7">{{ $t('Pending') }}</div>
          <div class="text-h5" :class="status.outbox.pending ? 'text-amber-9' : 'text-green'">{{ status.outbox.pending }}</div>
        </q-card>
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 130px">
          <div class="text-caption text-grey-7">{{ $t('Synced') }}</div>
          <div class="text-h5 text-green">{{ status.outbox.synced }}</div>
        </q-card>
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 130px">
          <div class="text-caption text-grey-7">{{ $t('Failed') }}</div>
          <div class="text-h5" :class="status.outbox.failed ? 'text-red' : 'text-green'">{{ status.outbox.failed }}</div>
        </q-card>
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 130px">
          <div class="text-caption text-grey-7">{{ $t('PendingConflicts') }}</div>
          <div class="text-h5" :class="status.conflicts_pending ? 'text-deep-orange' : 'text-green'">{{ status.conflicts_pending }}</div>
        </q-card>
        <q-card flat bordered class="q-pa-md col-auto" style="min-width: 150px">
          <div class="text-caption text-grey-7">{{ $t('Cursor') }}</div>
          <div class="text-h6">#{{ status.cursor }}</div>
          <div class="text-caption text-grey-7">{{ status.device.device_id || '' }}</div>
        </q-card>
      </div>

      <q-banner v-if="status.last_error" rounded class="bg-red-1 q-mb-lg">
        <template v-slot:avatar><q-icon name="error" color="red" /></template>
        {{ status.last_error }}
      </q-banner>

      <!-- Last run summary -->
      <q-card v-if="summary" flat bordered class="q-pa-md q-mb-lg">
        <div class="text-h6 q-mb-sm">{{ $t('LastSyncResult') }}</div>
        <div class="row gap-lg text-body2">
          <div>
            <q-icon name="upload" color="green" /> {{ $t('Push') }}:
            {{ summary.push.applied }} {{ $t('AppliedCount') }},
            {{ summary.push.duplicates }} {{ $t('DuplicatesCount') }},
            {{ summary.push.conflicts }} {{ $t('ConflictsCount') }},
            {{ summary.push.rejected }} {{ $t('RejectedCount') }}
          </div>
          <div>
            <q-icon name="download" color="blue" /> {{ $t('Pull') }}:
            {{ summary.pull.applied }} {{ $t('AppliedCount') }},
            {{ summary.pull.failed }} {{ $t('Failed') }},
            {{ summary.pull.conflicts }} {{ $t('ConflictsCount') }}
          </div>
          <div class="text-grey-7">{{ $t('Cursor') }} {{ summary.cursor_before }} → {{ summary.cursor_after }}</div>
        </div>
      </q-card>

      <!-- Outbox -->
      <div class="row items-center gap-md q-mb-sm">
        <div class="text-h6">{{ $t('Outbox') }}</div>
        <q-select
          v-model="outboxStatus" outlined dense
          :options="['pending', 'processing', 'synced', 'failed', 'conflict']"
          :label="$t('Status')" clearable style="min-width: 150px"
          @update:model-value="loadOutbox"
        />
        <q-btn
          v-if="$can('sync-now') && (status.outbox.failed || status.outbox.conflict)"
          flat no-caps color="primary" icon="replay"
          :label="$t('RetryAll')" :loading="retrying" @click="retryAll"
        />
      </div>
      <q-table
        :rows="outbox"
        :columns="outboxColumns"
        row-key="id"
        flat bordered dense
        :loading="loadingOutbox"
        :pagination="{ rowsPerPage: 15 }"
        class="q-mb-xl"
      >
        <template v-slot:body-cell-status="props">
          <q-td :props="props">
            <q-chip
              :color="{ pending: 'amber-9', processing: 'blue', synced: 'green', failed: 'red', conflict: 'deep-orange' }[props.row.status] || 'grey'"
              text-color="white" size="sm" :label="props.row.status"
            />
          </q-td>
        </template>
        <template v-slot:body-cell-actions="props">
          <q-td :props="props">
            <q-btn
              v-if="$can('sync-now') && (props.row.status === 'failed' || props.row.status === 'conflict')"
              flat dense no-caps size="sm" icon="replay" :label="$t('Retry')"
              @click="retryOne(props.row.id)"
            />
          </q-td>
        </template>
        <template v-slot:body-cell-last_error="props">
          <q-td :props="props" class="ellipsis" style="max-width: 320px">
            {{ props.row.last_error || '' }}
            <q-tooltip v-if="props.row.last_error">{{ props.row.last_error }}</q-tooltip>
          </q-td>
        </template>
      </q-table>

      <!-- Conflicts -->
      <div class="text-h6 q-mb-sm">{{ $t('SyncConflicts') }}</div>
      <div v-if="!conflicts.length" class="text-grey-7 q-mb-xl">{{ $t('NoConflicts') }}</div>
      <q-list v-else bordered separator dense class="rounded-borders q-mb-xl">
        <q-item v-for="c in conflicts" :key="c.id">
          <q-item-section avatar>
            <q-icon name="sync_problem" :color="c.severity === 'critical' ? 'red' : 'deep-orange'" />
          </q-item-section>
          <q-item-section>
            <q-item-label>{{ c.entity_type }} · <span class="text-grey-7">{{ c.entity_uuid.slice(0, 8) }}…</span></q-item-label>
            <q-item-label caption>{{ c.reason }}</q-item-label>
            <q-item-label caption v-if="c.differing_fields">
              {{ $t('DifferingFields') }}: {{ Object.keys(c.differing_fields).join(', ') }}
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <q-chip :label="c.status" size="sm" :color="c.status === 'pending' ? 'amber-9' : 'green'" text-color="white" />
          </q-item-section>
        </q-item>
      </q-list>

      <!-- Backups -->
      <div v-if="$can('manage-devices')">
        <div class="row items-center gap-md q-mb-sm">
          <div class="text-h6">{{ $t('Backups') }}</div>
          <q-btn flat no-caps icon="backup" :label="$t('BackupNow')" :loading="backingUp" @click="backupNow" />
        </div>
        <q-table
          :rows="backups"
          :columns="backupColumns"
          row-key="name"
          flat bordered dense
          :pagination="{ rowsPerPage: 8 }"
        >
          <template v-slot:body-cell-actions="props">
            <q-td :props="props">
              <q-btn flat dense no-caps size="sm" icon="restore" :label="$t('Restore')" @click="restoreBackup(props.row)" />
            </q-td>
          </template>
        </q-table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/boot/axios'
import { useQuasar } from 'quasar'
import { i18n } from '@/boot/i18n'
import { fmtDateTime } from '@/utils/date'

const $q = useQuasar()
const t = i18n.t

const loading = ref(false)
const syncing = ref(false)
const registering = ref(false)
const retrying = ref(false)
const backingUp = ref(false)
const loadingOutbox = ref(false)

const isOffline = ref(false)
const status = ref(null)
const summary = ref(null)
const outbox = ref([])
const outboxStatus = ref('pending')
const conflicts = ref([])
const backups = ref([])
const reg = ref({ device_id: '', activation_code: '' })

const outboxColumns = [
  { name: 'id', label: '#', field: 'id', align: 'right' },
  { name: 'entity_type', label: t('Entity'), field: 'entity_type', align: 'left' },
  { name: 'operation', label: t('Operation'), field: 'operation', align: 'left' },
  { name: 'status', label: t('Status'), field: 'status', align: 'left' },
  { name: 'attempts', label: t('Attempts'), field: 'attempts', align: 'right' },
  { name: 'last_error', label: t('LastError'), field: 'last_error', align: 'left' },
  { name: 'created_at', label: t('CreatedAt'), field: 'created_at', align: 'left', format: (v) => fmtDateTime(v) },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const backupColumns = [
  { name: 'name', label: t('FileName'), field: 'name', align: 'left' },
  { name: 'size', label: t('FileSize'), field: 'size', align: 'right', format: (v) => (v / 1048576).toFixed(1) + ' MB' },
  { name: 'created_at', label: t('CreatedAt'), field: 'created_at', align: 'left', format: (v) => fmtDateTime(v) },
  { name: 'actions', label: '', field: 'actions', align: 'right' },
]

const connectionLabel = computed(() => {
  if (status.value?.reachable === true) return t('Online')
  if (status.value?.reachable === false) return t('Offline')
  return t('Unknown')
})

const connectionIcon = computed(() => {
  if (status.value?.reachable === true) return 'cloud_done'
  if (status.value?.reachable === false) return 'cloud_off'
  return 'cloud_queue'
})

const connectionClass = computed(() => {
  if (status.value?.reachable === true) return 'text-green'
  if (status.value?.reachable === false) return 'text-red'
  return 'text-grey-7'
})

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/offline/status', { params: { probe: true } })
    isOffline.value = true
    status.value = data
    summary.value = data.last_summary || null
    loadOutbox()
    loadConflicts()
    loadBackups()
  } catch (e) {
    // 404 = an Online installation: this page becomes an explainer, not an error.
    isOffline.value = e.response?.status !== 404 ? true : false
    if (isOffline.value) {
      $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
    }
  } finally {
    loading.value = false
  }
}

async function syncNow() {
  syncing.value = true
  try {
    const { data } = await api.post('/offline/sync')
    summary.value = data.summary
    $q.notify({ type: 'positive', message: t('SyncCompleted') })
    load()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || t('SyncFailed') })
    load()
  } finally {
    syncing.value = false
  }
}

async function registerDevice() {
  registering.value = true
  try {
    await api.post('/offline/register', reg.value)
    $q.notify({ type: 'positive', message: t('DeviceRegistered') })
    load()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    registering.value = false
  }
}

async function loadOutbox() {
  loadingOutbox.value = true
  try {
    const { data } = await api.get('/offline/outbox', {
      params: { status: outboxStatus.value || undefined, per_page: 100 },
    })
    outbox.value = data.data || []
  } finally {
    loadingOutbox.value = false
  }
}

async function retryOne(id) {
  try {
    await api.post('/offline/retry', { id })
    $q.notify({ type: 'positive', message: t('Requeued') })
    load()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  }
}

async function retryAll() {
  retrying.value = true
  try {
    const { data } = await api.post('/offline/retry')
    $q.notify({ type: 'positive', message: `${t('Requeued')}: ${data.requeued}` })
    load()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    retrying.value = false
  }
}

async function loadConflicts() {
  try {
    const { data } = await api.get('/offline/conflicts')
    conflicts.value = data.local || []
  } catch {
    conflicts.value = []
  }
}

async function loadBackups() {
  try {
    const { data } = await api.get('/offline/backups')
    backups.value = data.data || []
  } catch {
    backups.value = []
  }
}

async function backupNow() {
  backingUp.value = true
  try {
    await api.post('/offline/backup')
    $q.notify({ type: 'positive', message: t('BackupCreated') })
    loadBackups()
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    backingUp.value = false
  }
}

function restoreBackup(row) {
  $q.dialog({
    title: t('Restore'),
    message: t('OfflineRestoreConfirm'),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      await api.post('/offline/restore', { file: row.file })
      $q.notify({ type: 'positive', message: t('RestoreDone') })
      load()
    } catch (e) {
      $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
    }
  })
}

onMounted(load)
</script>
