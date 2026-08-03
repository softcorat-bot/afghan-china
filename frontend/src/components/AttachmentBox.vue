<template>
  <div class="attach-box">
    <div class="attach-box__head" v-if="label || !readonly">
      <div class="attach-box__title">
        <q-icon :name="icon" size="17px" class="q-mr-xs" />{{ label || $t('Attachments') }}
        <span v-if="items.length" class="attach-box__count">{{ items.length }}</span>
      </div>
      <q-space />
      <q-btn v-if="!readonly && (max === 0 || items.length < max)" dense no-caps unelevated
        color="primary" icon="add_photo_alternate" :label="$t('Attach')" size="sm"
        :loading="uploading" @click="pick" />
    </div>

    <input ref="fileInput" type="file" :accept="accept" multiple class="hidden" @change="onFiles" />

    <div v-if="loading" class="attach-box__empty"><q-spinner size="18px" /> {{ $t('Loading') }}…</div>

    <div v-else-if="items.length === 0" class="attach-box__empty">
      <q-icon name="image_not_supported" size="20px" class="q-mr-xs" />{{ $t('NoAttachments') }}
    </div>

    <div v-else class="attach-grid">
      <div v-for="a in items" :key="a.id" class="attach-cell" @click="preview(a)">
        <img v-if="a.is_image && thumbs[a.id]" :src="thumbs[a.id]" :alt="a.original_name" />
        <div v-else-if="a.is_image" class="attach-cell__ph"><q-spinner size="16px" /></div>
        <div v-else class="attach-cell__ph"><q-icon :name="fileIcon(a.mime)" size="26px" color="blue-grey-5" /></div>
        <div class="attach-cell__cap">{{ a.caption || a.original_name }}</div>
        <q-btn v-if="!readonly" round dense size="xs" color="negative" icon="close"
          class="attach-cell__del" @click.stop="remove(a)" />
      </div>
    </div>

    <!-- Preview dialog -->
    <q-dialog v-model="viewer">
      <q-card style="max-width: 92vw; max-height: 92vh">
        <q-card-section class="row items-center q-py-sm">
          <div class="text-subtitle2 ellipsis">{{ current?.original_name }}</div>
          <q-space />
          <q-btn flat round dense icon="download" @click="download(current)" />
          <q-btn flat round dense icon="close" v-close-popup />
        </q-card-section>
        <q-separator />
        <q-card-section class="q-pa-none text-center bg-grey-2">
          <img v-if="current?.is_image && thumbs[current?.id]" :src="thumbs[current.id]"
            style="max-width: 100%; max-height: 78vh; object-fit: contain" />
          <div v-else class="q-pa-xl text-grey-7">
            <q-icon :name="fileIcon(current?.mime)" size="64px" /><br>{{ current?.original_name }}
          </div>
        </q-card-section>
      </q-card>
    </q-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const props = defineProps({
  type: { type: String, required: true },        // whitelist alias, e.g. 'purchase-request'
  id: { type: [Number, String], default: null },
  kind: { type: String, default: 'file' },        // file | receipt | photo | avatar
  label: { type: String, default: null },
  icon: { type: String, default: 'attach_file' },
  accept: { type: String, default: 'image/*,application/pdf' },
  readonly: { type: Boolean, default: false },
  max: { type: Number, default: 0 }               // 0 = unlimited
})
const emit = defineEmits(['count'])

const items = ref([])
const thumbs = reactive({})
const loading = ref(false)
const uploading = ref(false)
const fileInput = ref(null)
const viewer = ref(false)
const current = ref(null)

async function load () {
  if (!props.id) { items.value = []; return }
  loading.value = true
  try {
    const { data } = await api.get('/attachments', { params: { type: props.type, id: props.id, kind: props.kind } })
    items.value = data || []
    emit('count', items.value.length)
    for (const a of items.value) if (a.is_image) loadThumb(a)
  } finally { loading.value = false }
}

async function loadThumb (a) {
  if (thumbs[a.id]) return
  try {
    const res = await api.get(`/attachments/${a.id}/view`, { responseType: 'blob' })
    thumbs[a.id] = URL.createObjectURL(res.data)
  } catch (_) {}
}

function pick () { fileInput.value?.click() }

async function onFiles (e) {
  const files = Array.from(e.target.files || [])
  e.target.value = ''
  if (!files.length || !props.id) return
  uploading.value = true
  try {
    for (const raw of files) {
      const fd = new FormData()
      fd.append('type', props.type)
      fd.append('id', props.id)
      fd.append('kind', props.kind)
      fd.append('file', raw)
      await api.post('/attachments', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    }
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Uploaded' })
    await load()
  } catch (err) {
    Notify.create({ type: 'negative', message: err?.response?.data?.message || 'Upload failed' })
  } finally { uploading.value = false }
}

function remove (a) {
  api.delete(`/attachments/${a.id}`).then(() => {
    items.value = items.value.filter(x => x.id !== a.id)
    emit('count', items.value.length)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'delete', message: 'Removed' })
  }).catch(() => Notify.create({ type: 'negative', message: 'Delete failed' }))
}

function preview (a) { current.value = a; viewer.value = true; if (a.is_image) loadThumb(a) }

async function download (a) {
  if (!a) return
  const res = await api.get(`/attachments/${a.id}/view`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  const link = document.createElement('a')
  link.href = url; link.download = a.original_name || 'file'
  link.click(); URL.revokeObjectURL(url)
}

function fileIcon (mime) {
  if (!mime) return 'insert_drive_file'
  if (mime.includes('pdf')) return 'picture_as_pdf'
  if (mime.startsWith('image/')) return 'image'
  if (mime.includes('word') || mime.includes('document')) return 'description'
  if (mime.includes('sheet') || mime.includes('excel')) return 'table_chart'
  return 'insert_drive_file'
}

watch(() => props.id, load)
onMounted(load)
defineExpose({ reload: load })
</script>

<style scoped>
.attach-box { width: 100%; }
.attach-box__head { display: flex; align-items: center; margin-bottom: 8px; }
.attach-box__title { font-size: 13px; font-weight: 800; color: var(--on-surface, #1E293B); display: flex; align-items: center; }
.attach-box__count { font-size: 11px; font-weight: 800; background: color-mix(in srgb, var(--q-primary) 14%, #fff); color: var(--q-primary); border-radius: 20px; padding: 1px 8px; margin-left: 6px; }
.attach-box__empty { display: flex; align-items: center; justify-content: center; gap: 4px; color: #94A3B8; font-size: 12px; padding: 18px; border: 1px dashed #E2E8F0; border-radius: 12px; background: #FAFCFE; }
.attach-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
.attach-cell { position: relative; border: 1px solid #E7ECF3; border-radius: 12px; overflow: hidden; cursor: pointer; background: #fff; aspect-ratio: 1 / 1; transition: all 0.2s ease; }
.attach-cell:hover { border-color: var(--q-primary); box-shadow: 0 8px 18px -12px rgba(18, 58, 102, 0.4); transform: translateY(-2px); }
.attach-cell img { width: 100%; height: 74%; object-fit: cover; display: block; }
.attach-cell__ph { width: 100%; height: 74%; display: flex; align-items: center; justify-content: center; background: #F1F5F9; }
.attach-cell__cap { font-size: 10px; color: #64748B; padding: 3px 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.attach-cell__del { position: absolute; top: 4px; right: 4px; opacity: 0.9; }
.hidden { display: none; }
</style>
