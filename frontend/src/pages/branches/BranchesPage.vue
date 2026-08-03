<template>
  <q-page class="br-page q-pa-md">

    <!-- ── VIP hero — the branch network ────────────────────────── -->
    <div class="br-hero q-mb-md">
      <div class="br-hero__left">
        <div class="br-hero__eyebrow"><q-icon name="storefront" size="15px" /> {{ $t('BranchNetwork') }}</div>
        <div class="br-hero__title">{{ $t('Branches') }}</div>
        <div class="br-hero__sub">{{ $t('BranchesHint') }}</div>
      </div>
      <div class="br-hero__stats">
        <div class="br-hstat">
          <div class="br-hstat__val">{{ rows.length }}</div>
          <div class="br-hstat__lbl">{{ $t('Locations') }}</div>
        </div>
        <div class="br-hstat">
          <div class="br-hstat__val">{{ activeCount }}</div>
          <div class="br-hstat__lbl">{{ $t('Active') }}</div>
        </div>
        <div class="br-hstat br-hstat--gold">
          <div class="br-hstat__val">{{ fmt(networkToday) }} <small>AFN</small></div>
          <div class="br-hstat__lbl">{{ $t('NetworkSalesToday') }}</div>
        </div>
        <q-btn v-if="$can('branch-create')" unelevated no-caps icon="add_business" :label="$t('AddBranch')" class="br-addbtn" @click="openCreate" />
      </div>
    </div>

    <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="40px" /></div>

    <!-- ── Branch cards ─────────────────────────────────────────── -->
    <div v-else class="row q-col-gutter-md">
      <div v-for="b in rows" :key="b.id" class="col-12 col-md-6 col-lg-4">
        <div class="br-card" :class="{ 'br-card--off': !b.active, 'br-card--current': b.id === auth.user?.current_branch }">
          <div class="br-card__head">
            <div class="br-avatar"><q-icon name="store" size="21px" /></div>
            <div class="min-w-0">
              <div class="br-card__name">{{ b.name }}</div>
              <div class="br-card__addr">
                <q-icon name="place" size="12px" /> {{ b.address || '—' }}
                <template v-if="b.phone"> · <q-icon name="phone" size="12px" /> {{ b.phone }}</template>
              </div>
            </div>
            <q-space />
            <q-chip dense square :color="b.active ? 'green-6' : 'blue-grey-5'" text-color="white" class="q-ma-none">
              {{ b.active ? $t('Active') : $t('Inactive') }}
            </q-chip>
          </div>

          <div class="br-card__sales">
            <div>
              <div class="br-card__big">{{ fmt(b.sales_today) }} <small>AFN</small></div>
              <div class="br-card__lbl">{{ $t('SalesToday') }} · {{ b.orders_today }} {{ $t('Orders') }}</div>
            </div>
            <div class="br-card__month">
              <div class="br-card__big2">{{ fmt(b.sales_30d) }}</div>
              <div class="br-card__lbl">{{ $t('Last30Days') }}</div>
            </div>
          </div>

          <!-- share of today's network takings -->
          <div class="br-share">
            <div class="br-share__track"><div class="br-share__bar" :style="`width:${sharePct(b)}%`"></div></div>
            <span class="br-share__pct">{{ sharePct(b) }}%</span>
          </div>

          <div class="br-card__meta">
            <span><q-icon name="groups" size="14px" /> {{ b.team_count }} {{ $t('TeamMembers') }}</span>
            <span><q-icon name="savings" size="14px" /> {{ b.open_shifts }} {{ $t('OpenShifts') }}</span>
            <span v-if="b.id === auth.user?.current_branch" class="br-card__here"><q-icon name="my_location" size="14px" /> {{ $t('YouAreHere') }}</span>
          </div>

          <div class="br-card__actions">
            <q-btn v-if="$can('branch-edit')" dense flat no-caps size="sm" color="primary" icon="edit" :label="$t('Edit')" @click="openEdit(b.id)" />
            <q-btn v-if="$can('branch-delete')" dense flat no-caps size="sm" color="red-5" icon="delete" :label="$t('Delete')" @click="remove(b.id)" />
          </div>
        </div>
      </div>

      <div v-if="!rows.length" class="col-12 text-center text-grey-6 q-py-xl">
        <q-icon name="add_business" size="36px" color="amber-8" /><br>{{ $t('NoRecordFound') }}
      </div>
    </div>

    <!-- Add / edit branch -->
    <m-modal :showCM="dialog" @update:showCM="dialog = $event" card_style="width: 480px">
      <q-card class="bg-white">
        <n-header icon="store">{{ editing ? $t('Edit') : $t('AddNew') }} — {{ $t('Branch') }}</n-header>
        <q-separator />
        <q-form @submit="save" @reset="resetForm">
          <q-card-section class="row q-col-gutter-sm">
            <div class="col-12 col-sm-6">
              <n-name :name="form.name" @update:name="form.name = $event" icon="store" :label="$t('Name')" autofocus />
            </div>
            <div class="col-12 col-sm-6">
              <n-name :name="form.phone" @update:name="form.phone = $event" icon="phone" :label="$t('Phone')" :rules="[]" />
            </div>
            <div class="col-12 col-sm-6 flex items-center">
              <q-toggle v-model="form.active" :label="$t('Active')" color="primary" />
            </div>
            <div class="col-12">
              <n-name :name="form.address" @update:name="form.address = $event" icon="home" :label="$t('Address')" :rules="[]" />
            </div>
          </q-card-section>
          <q-separator />
          <n-submit :submitting="saving" :label="$t('Save')" />
        </q-form>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, getCurrentInstance, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

const { proxy } = getCurrentInstance()
const auth = useAuthStore()

const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const dialog = ref(false)
const editing = ref(null)

const blank = () => ({ name: '', address: '', phone: '', active: true })
const form = reactive(blank())

const activeCount = computed(() => rows.value.filter(b => b.active).length)
const networkToday = computed(() => rows.value.reduce((s, b) => s + Number(b.sales_today || 0), 0))

function fmt (v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }) }
function sharePct (b) {
  return networkToday.value > 0 ? Math.round(Number(b.sales_today || 0) / networkToday.value * 100) : 0
}

function resetForm () { Object.assign(form, blank()) }

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/branches')
    rows.value = data
  } finally {
    loading.value = false
  }
}

function openCreate () {
  editing.value = null
  resetForm()
  dialog.value = true
}

function openEdit (id) {
  const row = rows.value.find(r => r.id === id)
  if (!row) return
  editing.value = id
  Object.assign(form, { name: row.name, address: row.address, phone: row.phone, active: !!row.active })
  dialog.value = true
}

async function save () {
  saving.value = true
  try {
    if (editing.value) await api.put(`/branches/${editing.value}`, form)
    else await api.post('/branches', form)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'cloud_done', message: 'Saved successfully' })
    dialog.value = false
    load()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Save failed' })
  } finally {
    saving.value = false
  }
}

function remove (id) {
  proxy.$delete(`branches/${id}`, load)
}

onMounted(load)
</script>

<style scoped>
.br-page { background: #F0F4F8; }

/* Hero */
.br-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.br-hero__left { flex: 1 1 280px; }
.br-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.br-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.br-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 4px; max-width: 480px; }
.br-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.br-hstat {
  min-width: 110px; padding: 10px 14px; border-radius: 14px;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.br-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.br-hstat__val { font-size: 17px; font-weight: 900; }
.br-hstat--gold .br-hstat__val { color: #F3D48B; }
.br-hstat__val small { font-size: 11px; font-weight: 700; color: #9FC1E0; }
.br-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }
.br-addbtn {
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
  font-weight: 900; border-radius: 12px; padding: 10px 18px;
}

/* Branch cards */
.br-card {
  position: relative; background: #fff; border: 1.5px solid #E7ECF3; border-radius: 18px;
  padding: 16px 18px; height: 100%; transition: all 0.2s ease;
}
.br-card:hover { transform: translateY(-3px); box-shadow: 0 16px 30px -20px rgba(14, 42, 71, 0.6); }
.br-card--current { border-color: #F3D48B; box-shadow: 0 14px 30px -18px rgba(200, 134, 45, 0.45); }
.br-card--off { opacity: 0.65; }
.br-card__head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.br-avatar {
  width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0E2A47, #17517F); color: #F3D48B;
  border: 1.5px solid #F3D48B;
}
.br-card__name { font-size: 14.5px; font-weight: 800; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.br-card__addr { font-size: 10.5px; color: #94A3B8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.br-card__sales { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; }
.br-card__big { font-size: 24px; font-weight: 900; color: #0E2A47; letter-spacing: -0.5px; }
.br-card__big small { font-size: 12px; color: #94A3B8; font-weight: 700; }
.br-card__big2 { font-size: 15px; font-weight: 800; color: #334155; text-align: end; }
.br-card__lbl { font-size: 10.5px; color: #94A3B8; }
.br-share { display: flex; align-items: center; gap: 8px; margin: 10px 0; }
.br-share__track { flex: 1; height: 7px; border-radius: 6px; background: #F1F5F9; overflow: hidden; }
.br-share__bar { height: 100%; border-radius: 6px; background: linear-gradient(90deg, #17517F, #C8862D); transition: width 0.6s ease; }
.br-share__pct { font-size: 10.5px; font-weight: 800; color: #64748B; }
.br-card__meta { display: flex; gap: 14px; flex-wrap: wrap; font-size: 11px; color: #64748B; }
.br-card__meta .q-icon { color: #C8862D; margin-inline-end: 3px; }
.br-card__here { color: #B45309; font-weight: 800; }
.br-card__actions { display: flex; justify-content: flex-end; gap: 4px; margin-top: 10px; border-top: 1px dashed #EEF2F7; padding-top: 8px; }
.min-w-0 { min-width: 0; }
</style>
