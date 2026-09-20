<template>
  <div class="q-pa-md">
    <div class="row items-center justify-between q-mb-lg">
      <h1 class="text-h4 q-ma-none">{{ $t('SyncConflicts') }}</h1>
      <q-btn flat no-caps icon="refresh" :label="$t('Refresh')" :loading="loading" @click="load" />
    </div>

    <!-- Filters -->
    <div class="row gap-md q-mb-lg">
      <q-toggle v-model="pendingOnly" :label="$t('PendingOnly')" @update:model-value="load" />
      <q-select
        v-model="filterSeverity"
        outlined dense
        :options="['critical', 'warning', 'info']"
        :label="$t('Severity')" clearable class="col-auto" style="min-width: 140px"
        @update:model-value="load"
      />
      <q-select
        v-model="filterEntity"
        outlined dense
        :options="entityTypes"
        :label="$t('Entity')" clearable class="col-auto" style="min-width: 170px"
        @update:model-value="load"
      />
    </div>

    <q-table
      :rows="conflicts"
      :columns="columns"
      row-key="id"
      flat bordered
      :loading="loading"
      v-model:pagination="pagination"
      @request="onRequest"
      :no-data-label="$t('NoConflicts')"
    >
      <template v-slot:body-cell-severity="props">
        <q-td :props="props">
          <q-chip
            :color="props.row.severity === 'critical' ? 'red' : (props.row.severity === 'warning' ? 'amber-9' : 'blue-grey')"
            text-color="white" size="sm"
            :label="props.row.severity"
          />
        </q-td>
      </template>
      <template v-slot:body-cell-entity="props">
        <q-td :props="props">
          <div class="text-weight-medium">{{ props.row.entity_type }}</div>
          <div class="text-caption text-grey-7">{{ shortUuid(props.row.entity_uuid) }}</div>
        </q-td>
      </template>
      <template v-slot:body-cell-device="props">
        <q-td :props="props">
          <div>{{ props.row.device?.name || props.row.device_id }}</div>
          <div class="text-caption text-grey-7">{{ props.row.device_id }}</div>
        </q-td>
      </template>
      <template v-slot:body-cell-detected_at="props">
        <q-td :props="props">{{ fmtDateTime(props.row.detected_at) }}</q-td>
      </template>
      <template v-slot:body-cell-status="props">
        <q-td :props="props">
          <q-chip
            :color="props.row.status === 'pending' ? 'deep-orange' : 'green'"
            text-color="white" size="sm"
            :label="$t('ConflictStatus_' + props.row.status)"
          />
          <div v-if="props.row.resolver" class="text-caption text-grey-7 q-mt-xs">
            {{ $t('ResolvedBy') }}: {{ props.row.resolver.name }}
          </div>
        </q-td>
      </template>
      <template v-slot:body-cell-actions="props">
        <q-td :props="props">
          <q-btn flat dense round icon="visibility" size="sm" @click="openConflict(props.row)">
            <q-tooltip>{{ $t('Details') }}</q-tooltip>
          </q-btn>
          <q-btn
            v-if="props.row.status === 'pending' && $can('resolve-sync-conflicts')"
            flat dense round icon="gavel" size="sm" color="primary"
            @click="openConflict(props.row)"
          >
            <q-tooltip>{{ $t('Resolve') }}</q-tooltip>
          </q-btn>
        </q-td>
      </template>
    </q-table>

    <!-- Detail / resolve dialog -->
    <q-dialog v-model="showDetail" persistent>
      <q-card style="min-width: 640px; max-width: 95vw">
        <q-card-section class="row items-center q-pb-none">
          <div class="text-h6">
            {{ $t('Conflict') }} #{{ current.conflict?.id }} — {{ current.conflict?.entity_type }}
            <q-chip v-if="current.options?.financial" color="red" text-color="white" size="sm" class="q-ml-sm">
              {{ $t('FinancialRecord') }}
            </q-chip>
          </div>
          <q-space />
          <q-btn icon="close" flat round dense @click="closeDetail" />
        </q-card-section>
        <q-separator />
        <q-card-section v-if="current.conflict" class="q-gutter-md">
          <div class="text-body2 text-grey-9">{{ current.options?.explanation }}</div>

          <q-list dense bordered separator class="rounded-borders">
            <q-item><q-item-section>{{ $t('Reason') }}</q-item-section><q-item-section side>{{ current.conflict.reason || '—' }}</q-item-section></q-item>
            <q-item><q-item-section>{{ $t('Status') }}</q-item-section><q-item-section side>
              <q-chip :color="current.conflict.status === 'pending' ? 'deep-orange' : 'green'" text-color="white" size="sm" :label="$t('ConflictStatus_' + current.conflict.status)" />
            </q-item-section></q-item>
            <q-item v-if="current.conflict.resolution_note"><q-item-section>{{ $t('ResolutionNote') }}</q-item-section><q-item-section side>{{ current.conflict.resolution_note }}</q-item-section></q-item>
            <q-item v-if="current.conflict.resolver"><q-item-section>{{ $t('ResolvedBy') }}</q-item-section><q-item-section side>{{ current.conflict.resolver.name }}</q-item-section></q-item>
          </q-list>

          <!-- Field-by-field comparison -->
          <div v-if="diffRows.length">
            <div class="text-subtitle1 q-mb-sm">{{ $t('DifferingFields') }}</div>
            <q-markup-table flat bordered dense>
              <thead>
                <tr>
                  <th class="text-left" style="width: 22%">{{ $t('Field') }}</th>
                  <th class="text-left" style="width: 39%">{{ $t('TillVersion') }}</th>
                  <th class="text-left" style="width: 39%">{{ $t('ServerVersion') }}</th>
                  <th v-if="resolution === 'merged'" class="text-center" style="width: 60px">{{ $t('MergeFields') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in diffRows" :key="row.field">
                  <td class="text-weight-medium">{{ row.field }}</td>
                  <td :class="{ 'bg-amber-1': differs(row) }">{{ printable(row.local) }}</td>
                  <td :class="{ 'bg-amber-1': differs(row) }">{{ printable(row.server) }}</td>
                  <td v-if="resolution === 'merged'" class="text-center">
                    <q-checkbox v-model="mergePick[row.field]" dense />
                  </td>
                </tr>
              </tbody>
            </q-markup-table>
          </div>

          <!-- Resolve form -->
          <template v-if="!current.options?.resolved && $can('resolve-sync-conflicts')">
            <q-select
              v-model="resolution"
              outlined dense
              :options="resolutionOptions"
              option-value="value" option-label="label" emit-value map-options
              :label="$t('Resolve')"
            />
            <q-input
              v-model="note"
              outlined dense type="textarea"
              :label="$t('ResolutionNote')"
            />
            <div class="row gap-sm justify-end">
              <q-btn flat no-caps :label="$t('Cancel')" @click="closeDetail" />
              <q-btn
                unelevated no-caps color="primary"
                :label="$t('Resolve')"
                :disable="!resolution"
                :loading="resolving"
                @click="resolveConflict"
              />
            </div>
          </template>
        </q-card-section>
      </q-card>
    </q-dialog>
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

const conflicts = ref([])
const loading = ref(false)
const resolving = ref(false)
const pendingOnly = ref(true)
const filterSeverity = ref(null)
const filterEntity = ref(null)

const pagination = ref({ sortBy: 'id', descending: true, page: 1, rowsPerPage: 25, rowsNumber: 0 })

const showDetail = ref(false)
const current = ref({ conflict: null, options: null })
const resolution = ref(null)
const note = ref('')
const mergePick = ref({})

const entityTypes = ['product', 'product_category', 'customer', 'sale', 'refund', 'stock_adjustment', 'cash_session', 'supplier']

const columns = [
  { name: 'severity', label: 'Severity', field: 'severity', align: 'left' },
  { name: 'entity', label: 'Entity', field: 'entity_type', align: 'left' },
  { name: 'device', label: 'Device', field: 'device_id', align: 'left' },
  { name: 'reason', label: 'Reason', field: 'reason', align: 'left' },
  { name: 'detected_at', label: 'Detected', field: 'detected_at', align: 'left', sortable: true },
  { name: 'status', label: 'Status', field: 'status', align: 'left', sortable: true },
  { name: 'actions', label: 'Actions', field: 'actions', align: 'center' },
]

const resolutionLabels = {
  accepted_server: 'AcceptServerVersion',
  kept_local: 'KeepTillVersion',
  merged: 'MergeFields',
  dismissed: 'DismissConflict',
}

const resolutionOptions = computed(() =>
  (current.value.options?.actions || []).map((action) => ({ value: action, label: t(resolutionLabels[action] || action) })),
)

function asObject(value) {
  if (!value) return {}
  if (typeof value === 'string') {
    try { return JSON.parse(value) } catch { return {} }
  }
  return value
}

// Rows for the side-by-side comparison: every differing field, plus whatever the
// two payloads contain, with both versions next to each other.
const diffRows = computed(() => {
  const local = asObject(current.value.conflict?.local_payload)
  const server = asObject(current.value.conflict?.server_payload)
  const fields = new Set([
    ...(current.value.options?.differing_fields || []),
    ...Object.keys(local),
    ...Object.keys(server),
  ])

  return [...fields]
    .filter((field) => !['uuid', 'sync_seq', 'revision', 'synced_at', 'origin'].includes(field))
    .map((field) => ({ field, local: local[field], server: server[field] }))
})

function differs(row) {
  return JSON.stringify(row.local ?? null) !== JSON.stringify(row.server ?? null)
}

function printable(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function shortUuid(value) {
  return value ? String(value).slice(0, 8) + '…' : '—'
}

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/sync-conflicts', {
      params: {
        page,
        per_page: pagination.value.rowsPerPage,
        pending_only: pendingOnly.value ? 1 : 0,
        severity: filterSeverity.value || undefined,
        entity_type: filterEntity.value || undefined,
      },
    })
    conflicts.value = data.data || []
    pagination.value.rowsNumber = data.total || 0
    pagination.value.page = data.current_page || 1
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    loading.value = false
  }
}

function onRequest(props) {
  pagination.value = props.pagination
  load(props.pagination.page)
}

async function openConflict(row) {
  current.value = { conflict: row, options: null }
  resolution.value = null
  note.value = ''
  mergePick.value = {}
  showDetail.value = true
  try {
    const { data } = await api.get(`/sync-conflicts/${row.id}`)
    current.value = data
    if (!data.options?.resolved) {
      resolution.value = data.options?.actions?.[0] ?? null
      for (const field of data.options?.mergeable_fields || []) mergePick.value[field] = false
    }
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  }
}

function closeDetail() {
  showDetail.value = false
  current.value = { conflict: null, options: null }
}

async function resolveConflict() {
  resolving.value = true
  try {
    const payload = { resolution: resolution.value, note: note.value || undefined }
    if (resolution.value === 'merged') {
      const merged = {}
      for (const row of diffRows.value) {
        if (mergePick.value[row.field]) merged[row.field] = row.local
      }
      payload.merged = merged
    }
    const { data } = await api.post(`/sync-conflicts/${current.value.conflict.id}/resolve`, payload)
    $q.notify({ type: 'positive', message: data.message || 'Resolved' })
    closeDetail()
    load(pagination.value.page)
  } catch (e) {
    $q.notify({ type: 'negative', message: e.response?.data?.message || 'Failed' })
  } finally {
    resolving.value = false
  }
}

onMounted(() => load())
</script>
