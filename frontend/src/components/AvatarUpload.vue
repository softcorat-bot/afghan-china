<template>
  <div class="avatar-up" :style="{ width: size + 'px', height: size + 'px' }">
    <img v-if="src" :src="src" class="avatar-up__img" :alt="name" />
    <div v-else class="avatar-up__ph" :style="{ fontSize: (size / 2.6) + 'px' }">
      {{ initials }}
    </div>

    <div v-if="!readonly" class="avatar-up__edit" @click="pick">
      <q-spinner v-if="uploading" color="white" size="16px" />
      <q-icon v-else name="photo_camera" size="15px" color="white" />
    </div>
    <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFile" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const props = defineProps({
  type: { type: String, required: true },
  id: { type: [Number, String], default: null },
  name: { type: String, default: '' },
  size: { type: Number, default: 96 },
  readonly: { type: Boolean, default: false }
})

const src = ref(null)
const uploading = ref(false)
const fileInput = ref(null)

const initials = computed(() => (props.name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase())

async function load () {
  src.value = null
  if (!props.id) return
  try {
    const { data } = await api.get('/attachments', { params: { type: props.type, id: props.id, kind: 'avatar' } })
    if (data?.[0]) {
      const res = await api.get(`/attachments/${data[0].id}/view`, { responseType: 'blob' })
      src.value = URL.createObjectURL(res.data)
    }
  } catch (_) {}
}

function pick () { fileInput.value?.click() }

async function onFile (e) {
  const raw = e.target.files?.[0]
  e.target.value = ''
  if (!raw || !props.id) return
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('type', props.type)
    fd.append('id', props.id)
    fd.append('kind', 'avatar')
    fd.append('file', raw)
    await api.post('/attachments', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    await load()
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Photo updated' })
  } catch (err) {
    Notify.create({ type: 'negative', message: err?.response?.data?.message || 'Upload failed' })
  } finally { uploading.value = false }
}

watch(() => props.id, load)
onMounted(load)
</script>

<style scoped>
.avatar-up { position: relative; border-radius: 50%; overflow: visible; flex-shrink: 0; }
.avatar-up__img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 4px 14px -6px rgba(15, 23, 42, 0.4); }
.avatar-up__ph { width: 100%; height: 100%; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; background: linear-gradient(135deg, #175A8C, #1E6BA8); border: 3px solid #fff; box-shadow: 0 4px 14px -6px rgba(15, 23, 42, 0.4); }
.avatar-up__edit { position: absolute; right: -2px; bottom: -2px; width: 28px; height: 28px; border-radius: 50%; background: var(--q-primary); display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid #fff; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.3); transition: transform 0.15s ease; }
.avatar-up__edit:hover { transform: scale(1.1); }
.hidden { display: none; }
</style>
