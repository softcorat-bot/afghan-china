<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm q-col-gutter-md">
        <div class="col-12">
          <m-header icon="backup" controlRoomButton="false" class="q-mt-xs">{{ $t('Backup') }}</m-header>
        </div>

        <!-- Download / create backup -->
        <div class="col-12 col-md-6">
          <q-card class="my_radio_less bg-white full-height">
            <q-card-section>
              <div class="row items-center q-gutter-sm q-mb-xs"><q-icon name="cloud_download" size="26px" color="cyan-8" /><div class="text-subtitle1 text-weight-bold">{{ $t('DatabaseBackup') }}</div></div>
              <div class="text-body2 text-grey-7 q-mb-md">{{ $t('BackupHint') }}</div>
              <progress-btn color="cyan-8" icon="cloud_download" :loading="loading" @click="download">{{ $t('DownloadBackup') }}</progress-btn>
            </q-card-section>
          </q-card>
        </div>

        <!-- Restore / import -->
        <div class="col-12 col-md-6">
          <q-card class="my_radio_less bg-white full-height">
            <q-card-section>
              <div class="row items-center q-gutter-sm q-mb-xs"><q-icon name="restore" size="26px" color="deep-orange-8" /><div class="text-subtitle1 text-weight-bold">{{ $t('RestoreImport') }}</div></div>
              <q-banner dense class="bg-orange-1 text-orange-9 rounded-borders q-mb-md"><template #avatar><q-icon name="warning" color="orange-8" /></template>{{ $t('RestoreWarning') }}</q-banner>
              <q-file outlined dense color="primary" v-model="restoreFile" :label="$t('ChooseBackupFile')" accept=".sqlite,.dump" max-file-size="536870912" clearable class="q-mb-sm"><template #prepend><q-icon name="attach_file" color="primary" /></template></q-file>
              <progress-btn color="deep-orange-8" icon="restore" :loading="restoring" :disable="!restoreFile" @click="confirmRestore">{{ $t('RestoreNow') }}</progress-btn>
            </q-card-section>
          </q-card>
        </div>

        <!-- Existing server backups -->
        <div class="col-12">
          <q-card class="my_radio_less bg-white">
            <q-card-section>
              <div class="text-subtitle1 text-weight-bold q-mb-sm">{{ $t('ExistingBackups') }}</div>
              <q-markup-table flat bordered dense class="my_radio_less">
                <thead><tr><th class="text-left">{{ $t('Name') }}</th><th class="text-right">{{ $t('Size') }}</th><th class="text-left">{{ $t('CreatedAt') }}</th></tr></thead>
                <tbody>
                  <tr v-if="!backups.length"><td colspan="3" class="text-center text-grey-5 q-py-md">{{ $t('NoRecordFound') }}</td></tr>
                  <tr v-for="b in backups" :key="b.name"><td>{{ b.name }}</td><td class="text-right">{{ (b.size / 1024).toFixed(0) }} KB</td><td>{{ b.created_at }}</td></tr>
                </tbody>
              </q-markup-table>
            </q-card-section>
          </q-card>
        </div>
      </div>
    </m-backgrounds>

    <!-- Restore confirm -->
    <m-modal :showCM="confirmDialog" @update:showCM="confirmDialog = $event" card_style="width: 460px">
      <q-card class="bg-white">
        <n-header icon="restore">{{ $t('RestoreImport') }}</n-header>
        <q-separator />
        <q-card-section>
          <div class="text-body2">{{ $t('RestoreConfirm') }}</div>
          <div class="text-caption text-grey-7 q-mt-sm">{{ restoreFile?.name }}</div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-sm">
          <q-btn flat :label="$t('Cancel')" color="grey-7" @click="confirmDialog = false" />
          <q-btn unelevated color="deep-orange-8" icon="restore" :label="$t('RestoreNow')" :loading="restoring" @click="doRestore" />
        </q-card-actions>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, onMounted, getCurrentInstance } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const { proxy } = getCurrentInstance()
const $t = (k) => (proxy?.$t ? proxy.$t(k) : k)

const loading = ref(false)
const restoring = ref(false)
const restoreFile = ref(null)
const confirmDialog = ref(false)
const backups = ref([])

async function loadBackups () { try { const { data } = await api.get('/backup/list'); backups.value = Array.isArray(data) ? data : [] } catch (_) {} }

async function download () {
  loading.value = true
  try {
    const res = await api.get('/backup/download', { responseType: 'blob' })
    const cd = res.headers['content-disposition'] || ''
    const name = (cd.match(/filename="?([^"]+)"?/) || [])[1] || `backup-${new Date().toISOString().slice(0, 10)}.sqlite`
    const url = URL.createObjectURL(new Blob([res.data]))
    const a = document.createElement('a'); a.href = url; a.download = name
    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: $t('Saved') })
    loadBackups()
  } catch (e) { Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Backup failed' }) } finally { loading.value = false }
}

function confirmRestore () { if (restoreFile.value) confirmDialog.value = true }
async function doRestore () {
  restoring.value = true
  try {
    const fd = new FormData(); fd.append('file', restoreFile.value)
    const { data } = await api.post('/backup/restore', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    confirmDialog.value = false; restoreFile.value = null
    Notify.create({ type: 'positive', position: 'bottom', timeout: 5000, icon: 'restore', message: data?.message || 'Restored' })
    loadBackups()
  } catch (e) { Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Restore failed' }) } finally { restoring.value = false }
}

onMounted(loadBackups)
</script>
