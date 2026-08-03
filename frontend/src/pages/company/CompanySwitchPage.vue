<template>
  <div class="switch-box">

    <!-- Header -->
    <div class="switch-brand">
      <brand-mark size="36" />
      <div>
        <div class="switch-brand__name">Afghan China MIS</div>
        <div class="switch-brand__ver">Shopping Center · v1.0</div>
      </div>
    </div>

    <div class="switch-title">Select Company</div>
    <div class="switch-sub">Choose a workspace to continue</div>

    <!-- Company cards -->
    <div class="switch-grid">
      <div
        v-for="c in companies"
        :key="c.id"
        class="switch-card"
        :class="{ 'switch-card--active': c.id === currentId }"
        @click="select(c)"
      >
        <div class="switch-card__avatar" :style="{ background: avatarColor(c) }">
          {{ (c.abbreviation || c.name_en || '?').charAt(0).toUpperCase() }}
        </div>
        <div class="switch-card__info">
          <div class="switch-card__name">{{ c.name_en }}</div>
          <div class="switch-card__type">{{ c.business_type || 'General' }}</div>
        </div>
        <q-icon v-if="c.id === currentId" name="check_circle" color="cyan-7" size="20px" />
        <q-spinner v-else-if="switching === c.id" color="cyan-7" size="20px" />
        <q-icon v-else name="chevron_right" color="grey-4" size="20px" />
      </div>
    </div>

    <!-- Actions -->
    <div class="switch-actions">
      <q-btn unelevated color="cyan-7" icon="add" label="New Company" class="full-width switch-new-btn"
        @click="router.push({ name: 'company-create' })" />
      <q-btn flat color="grey-6" label="Go to Dashboard" class="full-width q-mt-xs"
        @click="router.push({ name: 'dashboard' })" />
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/boot/axios'
import BrandMark from '@/components/general/BrandMark.vue'

const router = useRouter()
const $q = useQuasar()
const auth = useAuthStore()

const companies = ref([])
const switching = ref(null)
const currentId = computed(() => auth.currentCompany?.id)

const colors = ['#123A66', '#175A8C', '#8A5A1E', '#C8862D', '#0F766E', '#DC2626', '#7C3AED']
function avatarColor(c) {
  const idx = (c.id || 0) % colors.length
  return colors[idx]
}

async function load() {
  try {
    const { data } = await api.get('/company')
    companies.value = data
  } catch {
    // fallback: use companies from auth store
    companies.value = auth.user?.companies || []
  }
}

async function select(company) {
  if (switching.value) return
  switching.value = company.id
  try {
    await api.get(`/company/select/${company.id}`)
    await auth.fetchUser()
    $q.notify({ type: 'positive', position: 'bottom', icon: 'business', message: `Switched to ${company.name_en}` })
    router.push({ name: 'dashboard' })
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Switch failed' })
  } finally {
    switching.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.switch-box { width: 100%; }

.switch-brand {
  display: flex; align-items: center; gap: 10px; margin-bottom: 32px;
}
.switch-brand__name { font-size: 16px; font-weight: 700; color: #0F172A; }
.switch-brand__ver  { font-size: 11px; color: #94A3B8; }

.switch-title { font-size: 24px; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; margin-bottom: 6px; }
.switch-sub   { font-size: 14px; color: #64748B; margin-bottom: 24px; }

.switch-grid { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }

.switch-card {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 16px;
  border: 1.5px solid #E2E8F0;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.15s;
}
.switch-card:hover { border-color: #175A8C; background: #F0F7FF; }
.switch-card--active { border-color: #175A8C; background: #EAF3FB; }

.switch-card__avatar {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.switch-card__info { flex: 1; }
.switch-card__name { font-size: 14px; font-weight: 600; color: #0F172A; }
.switch-card__type { font-size: 11px; color: #94A3B8; margin-top: 2px; }

.switch-new-btn { height: 44px; border-radius: 10px !important; font-weight: 600; }
</style>
