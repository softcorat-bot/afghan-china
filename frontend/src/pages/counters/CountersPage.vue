<template>
  <q-page class="cn-page q-pa-md">

    <div class="cn-hero q-mb-md">
      <div class="cn-hero__left">
        <div class="cn-hero__eyebrow"><q-icon name="point_of_sale" size="15px" /> {{ $t('RegisterSeats') }}</div>
        <div class="cn-hero__title">{{ $t('Counters') }}</div>
        <div class="cn-hero__sub">{{ $t('CountersHint') }}</div>
      </div>
      <div class="cn-hero__stats">
        <div class="cn-hstat">
          <div class="cn-hstat__val">{{ rows.length }}</div>
          <div class="cn-hstat__lbl">{{ $t('Counters') }}</div>
        </div>
        <div class="cn-hstat cn-hstat--gold">
          <div class="cn-hstat__val">{{ fmt(totalToday) }}</div>
          <div class="cn-hstat__lbl">{{ $t('SalesToday') }}</div>
        </div>
        <q-btn v-if="auth.isSuperAdmin" unelevated no-caps icon="add" :label="$t('AddCounter')" class="cn-addbtn" @click="openAdd" />
      </div>
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="40px" /></div>
    <div v-else class="row q-col-gutter-md">
      <div v-for="c in rows" :key="c.id" class="col-12 col-sm-6 col-lg-3">
        <div class="cn-card" :class="{ 'cn-card--off': !c.active }" @click="$router.push('/counters/' + c.id)">
          <div class="row items-center q-mb-sm">
            <div class="cn-avatar">{{ seatNo(c) }}</div>
            <div class="q-ml-sm min-w-0">
              <div class="cn-card__name">{{ c.name }}</div>
              <div class="cn-card__meta">
                <template v-if="c.shift_open"><span class="cn-live"></span> {{ c.operator || '—' }}</template>
                <template v-else>{{ $t('NoOpenShift') }}</template>
              </div>
            </div>
            <q-space />
            <q-btn v-if="auth.isSuperAdmin" dense flat round size="sm" icon="edit" color="blue-grey-4" @click.stop="openEdit(c)" />
          </div>
          <div class="cn-card__big">{{ fmt(c.sales_today) }} <small>AFN</small></div>
          <div class="cn-card__meta">{{ c.orders_today }} {{ $t('OrdersToday') }}</div>
          <div class="cn-card__go"><q-icon name="insights" size="14px" /> {{ $t('ViewPerformance') }} <q-icon name="arrow_forward" size="13px" /></div>
        </div>
      </div>
      <div v-if="!rows.length" class="col-12 text-center text-grey-5 q-py-xl">{{ $t('NoRecordFound') }}</div>
    </div>

    <q-dialog v-model="dlg">
      <q-card class="cn-dlg">
        <q-card-section class="text-weight-bold">{{ form.id ? $t('Edit') : $t('AddCounter') }}</q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm">
          <q-input outlined dense v-model="form.name" :label="$t('Name')" autofocus @keyup.enter="save" />
          <q-toggle v-if="form.id" v-model="form.active" :label="$t('Active')" color="amber-9" />
        </q-card-section>
        <q-separator />
        <q-card-actions align="right">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps class="cn-addbtn" :label="$t('Save')" :loading="saving" :disable="!form.name" @click="save" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const rows = ref([])
const loading = ref(false)
const dlg = ref(false)
const saving = ref(false)
const form = reactive({ id: null, name: '', active: true })

const totalToday = computed(() => rows.value.reduce((s, c) => s + Number(c.sales_today || 0), 0))
function fmt (v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) }
function seatNo (c) { const m = /(\d+)\s*$/.exec(c.name || ''); return m ? m[1] : (c.name || '?')[0].toUpperCase() }

async function load () {
  loading.value = true
  try { rows.value = (await api.get('/counters')).data } finally { loading.value = false }
}
function openAdd () { Object.assign(form, { id: null, name: '', active: true }); dlg.value = true }
function openEdit (c) { Object.assign(form, { id: c.id, name: c.name, active: !!c.active }); dlg.value = true }
async function save () {
  saving.value = true
  try {
    if (form.id) await api.put('/counters/' + form.id, { name: form.name, active: form.active })
    else await api.post('/counters', { name: form.name })
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved' })
    dlg.value = false; load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally { saving.value = false }
}
onMounted(load)
</script>

<style scoped>
.cn-page { background: #F0F4F8; }
.cn-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.cn-hero__left { flex: 1 1 260px; }
.cn-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.cn-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.cn-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 4px; max-width: 480px; }
.cn-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.cn-hstat {
  min-width: 110px; padding: 10px 14px; border-radius: 14px;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.cn-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.cn-hstat__val { font-size: 17px; font-weight: 900; }
.cn-hstat--gold .cn-hstat__val { color: #F3D48B; }
.cn-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }
.cn-addbtn { background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; font-weight: 900; border-radius: 11px; }

.cn-card {
  background: #fff; border: 1.5px solid #E7ECF3; border-radius: 18px; padding: 15px 17px;
  cursor: pointer; height: 100%; transition: all 0.2s ease;
}
.cn-card:hover { transform: translateY(-3px); border-color: #F3D48B; box-shadow: 0 16px 30px -20px rgba(200, 134, 45, 0.6); }
.cn-card--off { opacity: 0.6; }
.cn-avatar {
  width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  font-size: 17px; font-weight: 900; border: 1.5px solid #F3D48B;
}
.cn-card__name { font-size: 14.5px; font-weight: 800; color: #0F172A; }
.cn-card__meta { font-size: 11px; color: #94A3B8; display: flex; align-items: center; gap: 5px; }
.cn-live { width: 7px; height: 7px; border-radius: 50%; background: #22C55E; display: inline-block; animation: cnlive 2s infinite; }
@keyframes cnlive { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.5); } 70% { box-shadow: 0 0 0 6px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
.cn-card__big { font-size: 23px; font-weight: 900; color: #0E2A47; margin-top: 4px; }
.cn-card__big small { font-size: 12px; color: #94A3B8; }
.cn-card__go { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: #C8862D; margin-top: 10px; }
.cn-dlg { min-width: 340px; border-radius: 14px; }
.min-w-0 { min-width: 0; }
</style>
