<template>
  <q-page class="tr-page q-pa-md">

    <!-- ── VIP hero — the Super Admin vault ─────────────────────── -->
    <div class="tr-hero q-mb-md">
      <div class="tr-hero__left">
        <div class="tr-hero__eyebrow"><q-icon name="mdi-shield-crown" size="15px" /> {{ $t('SuperAdminVault') }}</div>
        <div class="tr-hero__title">{{ $t('Trashes') }}</div>
        <div class="tr-hero__sub">{{ $t('TrashHint') }}</div>
      </div>
      <div class="tr-hero__stats">
        <div class="tr-hstat">
          <div class="tr-hstat__val">{{ totalTrashed }}</div>
          <div class="tr-hstat__lbl">{{ $t('DeletedRecords') }}</div>
        </div>
        <div class="tr-hstat tr-hstat--gold">
          <div class="tr-hstat__val">{{ affectedModules }}</div>
          <div class="tr-hstat__lbl">{{ $t('ModulesAffected') }}</div>
        </div>
      </div>
    </div>

    <q-card flat class="tr-card">
      <!-- Type selector chips with live counts -->
      <div class="q-pa-md q-pb-none">
        <div class="tr-chips">
          <button v-for="t in types" :key="t.key" class="tr-chip" :class="{ 'tr-chip--on': current === t.key }" @click="select(t.key)">
            <q-icon :name="t.icon" size="16px" />
            <span>{{ $t(t.label) }}</span>
            <b class="tr-chip__n" :class="{ 'tr-chip__n--zero': !counts[t.key] }">{{ counts[t.key] ?? 0 }}</b>
          </button>
        </div>
      </div>

      <!-- Deleted records of the selected type -->
      <div class="q-pa-md">
        <div class="tr-list">
          <div class="tr-row tr-row--head">
            <div>{{ $t('Name') }}</div>
            <div>{{ $t('Details') }}</div>
            <div>{{ $t('DeletedAt') }}</div>
            <div class="text-right">{{ $t('Actions') }}</div>
          </div>

          <div v-if="loading" class="q-py-xl flex flex-center"><q-spinner color="amber-8" size="34px" /></div>
          <div v-else-if="rows.length === 0" class="tr-empty">
            <q-icon name="recycling" size="34px" color="amber-8" />
            <div>{{ $t('TrashEmpty') }}</div>
          </div>

          <div v-else v-for="r in rows" :key="r.id" class="tr-row">
            <div class="tr-row__name">
              <span class="tr-row__badge"><q-icon :name="currentIcon" size="14px" /></span>
              {{ r.label }}
            </div>
            <div class="tr-row__meta">{{ r.sub || '—' }}</div>
            <div class="tr-row__meta">{{ r.deleted_at }}</div>
            <div class="text-right">
              <q-btn dense no-caps unelevated size="sm" class="tr-btn-restore q-mr-xs" icon="restore_from_trash" :label="$t('Restore')" :loading="busyId === r.id" @click="restore(r)" />
              <q-btn dense no-caps outline size="sm" color="red-5" icon="delete_forever" :label="$t('DeleteForever')" @click="confirmDestroy(r)" />
            </div>
          </div>
        </div>
      </div>
    </q-card>

    <!-- Permanent-delete confirmation -->
    <q-dialog v-model="confirmDlg">
      <q-card class="tr-confirm">
        <q-card-section class="row items-center q-gutter-sm">
          <q-avatar icon="delete_forever" color="red-1" text-color="negative" />
          <div>
            <div class="text-weight-bold">{{ $t('DeleteForever') }}?</div>
            <div class="text-caption text-grey-7">{{ target?.label }} — {{ $t('CannotBeUndone') }}</div>
          </div>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat no-caps color="grey-7" :label="$t('Cancel')" v-close-popup />
          <q-btn unelevated no-caps color="negative" icon="delete_forever" :label="$t('DeleteForever')" :loading="busyId === target?.id" @click="destroy" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const types = [
  { key: 'products', label: 'Products', icon: 'inventory_2' },
  { key: 'categories', label: 'ProductCategories', icon: 'category' },
  { key: 'customers', label: 'Customers', icon: 'groups' },
  { key: 'suppliers', label: 'Suppliers', icon: 'local_shipping' },
  { key: 'sales', label: 'Sales', icon: 'sell' },
  { key: 'purchases', label: 'Purchases', icon: 'shopping_cart' },
  { key: 'users', label: 'Users', icon: 'manage_accounts' },
  { key: 'branches', label: 'Branches', icon: 'store' },
  { key: 'currencies', label: 'Currencies', icon: 'attach_money' },
]

const counts = reactive({})
const current = ref('products')
const rows = ref([])
const loading = ref(false)
const busyId = ref(null)
const confirmDlg = ref(false)
const target = ref(null)

const totalTrashed = computed(() => Object.values(counts).reduce((s, n) => s + (Number(n) || 0), 0))
const affectedModules = computed(() => Object.values(counts).filter(n => Number(n) > 0).length)
const currentIcon = computed(() => types.find(t => t.key === current.value)?.icon || 'delete')

async function loadCounts () {
  try { Object.assign(counts, (await api.get('/trash-counts')).data) } catch (_) {}
}

async function loadRows () {
  loading.value = true
  try {
    const { data } = await api.get('/trash/' + current.value)
    rows.value = data
  } catch (_) { rows.value = [] } finally { loading.value = false }
}

function select (key) { current.value = key; loadRows() }

async function restore (r) {
  busyId.value = r.id
  try {
    await api.post(`/trash/${current.value}/${r.id}/restore`)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'restore_from_trash', message: 'Restored' })
    rows.value = rows.value.filter(x => x.id !== r.id)
    loadCounts()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Restore failed' })
  } finally { busyId.value = null }
}

function confirmDestroy (r) { target.value = r; confirmDlg.value = true }

async function destroy () {
  const r = target.value
  if (!r) return
  busyId.value = r.id
  try {
    await api.delete(`/trash/${current.value}/${r.id}`)
    Notify.create({ type: 'positive', position: 'bottom', icon: 'delete_forever', message: 'Permanently deleted' })
    rows.value = rows.value.filter(x => x.id !== r.id)
    confirmDlg.value = false
    loadCounts()
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Delete failed' })
  } finally { busyId.value = null }
}

onMounted(() => { loadCounts(); loadRows() })
</script>

<style scoped>
.tr-page { background: #F0F4F8; }

/* Hero — the same deep-navy VIP language as the Main Cost desk */
.tr-hero {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  background:
    radial-gradient(600px 200px at 90% -40%, rgba(243, 212, 139, 0.18), transparent 60%),
    linear-gradient(120deg, #0E2A47 0%, #123A66 55%, #17517F 100%);
  border-radius: 20px; padding: 20px 24px; color: #fff;
  border: 1px solid rgba(243, 212, 139, 0.35);
  box-shadow: 0 20px 40px -26px rgba(14, 42, 71, 0.9);
}
.tr-hero__left { flex: 1 1 280px; }
.tr-hero__eyebrow {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800;
  letter-spacing: 2.4px; text-transform: uppercase; color: #F3D48B;
}
.tr-hero__title { font-size: 26px; font-weight: 900; letter-spacing: -0.5px; margin-top: 2px; }
.tr-hero__sub { font-size: 12px; color: #9FC1E0; margin-top: 4px; max-width: 520px; }
.tr-hero__stats { display: flex; gap: 12px; flex-wrap: wrap; }
.tr-hstat {
  min-width: 130px; padding: 10px 14px; border-radius: 14px;
  background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12);
}
.tr-hstat--gold { background: rgba(243, 212, 139, 0.14); border-color: rgba(243, 212, 139, 0.45); }
.tr-hstat__val { font-size: 17px; font-weight: 900; }
.tr-hstat--gold .tr-hstat__val { color: #F3D48B; }
.tr-hstat__lbl { font-size: 10.5px; color: #9FC1E0; margin-top: 2px; }

.tr-card { border-radius: 18px; border: 1.5px solid #E7ECF3; overflow: hidden; }

/* Module chips */
.tr-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.tr-chip {
  display: inline-flex; align-items: center; gap: 6px;
  border: 1.5px solid #E7ECF3; background: #fff; border-radius: 10px;
  padding: 7px 12px; font-size: 12px; font-weight: 700; color: #475569;
  cursor: pointer; transition: all 0.18s ease; font-family: inherit;
}
.tr-chip:hover { border-color: #C8862D; transform: translateY(-1px); }
.tr-chip--on {
  border-color: #C8862D; color: #fff;
  background: linear-gradient(120deg, #0E2A47, #17517F);
  box-shadow: 0 10px 18px -12px rgba(14, 42, 71, 0.8);
}
.tr-chip__n {
  background: #EF4444; color: #fff; border-radius: 8px; font-size: 10.5px;
  min-width: 20px; text-align: center; padding: 1px 5px;
}
.tr-chip__n--zero { background: #CBD5E1; }
.tr-chip--on .tr-chip__n--zero { background: rgba(255, 255, 255, 0.25); }

/* Record rows */
.tr-list { border: 1.5px solid #EEF2F7; border-radius: 14px; overflow: hidden; }
.tr-row {
  display: grid; grid-template-columns: minmax(180px, 1.6fr) 1fr 1fr minmax(230px, 1.2fr);
  gap: 10px; align-items: center; padding: 9px 14px; border-bottom: 1px solid #F1F5F9;
}
.tr-row--head {
  background: #0E2A47; color: #F3D48B; font-size: 11px; font-weight: 800;
  letter-spacing: 0.6px; text-transform: uppercase; padding: 10px 14px;
}
.tr-row__name { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #0F172A; }
.tr-row__badge {
  width: 28px; height: 28px; border-radius: 9px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  background: #FEF7E8; color: #C8862D; border: 1px solid #F3D48B;
}
.tr-row__meta { font-size: 12px; color: #64748B; }
.tr-btn-restore {
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66; font-weight: 800;
  border-radius: 8px;
}
.tr-empty {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 42px 0; color: #94A3B8; font-size: 13px;
}
.tr-confirm { min-width: 340px; border-radius: 14px; }
</style>
