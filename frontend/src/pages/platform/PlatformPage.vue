<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <div class="vip-hero">
            <div class="vip-hero__l"><q-icon name="mdi-shield-crown" size="30px" /><div><div class="vip-hero__t">VIP Control Center</div><div class="vip-hero__s">Platform Owner — reserved operations across all organizations</div></div></div>
            <q-chip dense color="amber-8" text-color="white" icon="verified_user">{{ ownerEmail }}</q-chip>
          </div>
        </div>

        <div class="col-12 q-mt-md">
          <q-tabs v-model="tab" dense align="left" active-color="amber-9" indicator-color="amber-8" class="text-blue-grey-8">
            <q-tab name="dashboard" icon="dashboard" label="Platform" />
            <q-tab name="orgs" icon="domain" label="Organizations" />
            <q-tab name="branches" icon="store" label="Branches" />
            <q-tab name="requests" icon="fact_check" :label="'Requests' + (s.pending_requests ? ' (' + s.pending_requests + ')' : '')" />
            <q-tab name="audit" icon="history" label="Audit" />
          </q-tabs>
          <q-separator />

          <q-tab-panels v-model="tab" animated class="bg-transparent">
            <!-- DASHBOARD -->
            <q-tab-panel name="dashboard" class="q-pa-none q-pt-md">
              <div class="row q-col-gutter-sm">
                <div class="col-6 col-md-3" v-for="k in kpis" :key="k.label"><stat-card :icon="k.icon" :label="k.label" :value="k.value" :color="k.color" :tint="k.tint" /></div>
              </div>
              <q-card class="my_radio_less bg-white q-mt-md"><q-card-section>
                <div class="text-subtitle2 text-weight-bold q-mb-sm">Branch distribution by organization</div>
                <q-markup-table flat bordered dense>
                  <thead><tr><th class="text-left">Organization</th><th class="text-right">Branches</th><th class="text-center">Active</th><th class="text-center">Self-service</th></tr></thead>
                  <tbody><tr v-for="d in s.branch_distribution" :key="d.organization"><td>{{ d.organization }}</td><td class="text-right">{{ d.branches }}</td><td class="text-center"><q-icon :name="d.active ? 'check_circle' : 'cancel'" :color="d.active ? 'green' : 'grey'" /></td><td class="text-center"><q-icon :name="d.self_service ? 'lock_open' : 'lock'" :color="d.self_service ? 'green' : 'blue-grey-4'" /></td></tr></tbody>
                </q-markup-table>
              </q-card-section></q-card>
            </q-tab-panel>

            <!-- ORGANIZATIONS -->
            <q-tab-panel name="orgs" class="q-pa-none q-pt-md">
              <div class="row justify-end q-mb-sm"><q-btn unelevated color="amber-8" icon="add" label="New Organization" @click="orgDialog = true" /></div>
              <q-markup-table flat bordered dense class="bg-white">
                <thead><tr><th class="text-left">Name</th><th class="text-left">Abbr</th><th class="text-right">Branches</th><th class="text-center">Status</th><th class="text-center">Branch self-service</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                  <tr v-for="o in orgs" :key="o.id">
                    <td class="text-weight-medium">{{ o.name_en }}</td><td>{{ o.abbreviation }}</td><td class="text-right">{{ o.branches_count }}</td>
                    <td class="text-center"><q-chip dense size="sm" :color="o.active ? 'green-7' : 'red-7'" text-color="white">{{ o.active ? 'Active' : 'Suspended' }}</q-chip></td>
                    <td class="text-center"><q-toggle :model-value="!!o.branch_self_service" color="green" @update:model-value="v => setSelfService(o, v)" /></td>
                    <td class="text-right"><q-btn flat dense size="sm" :icon="o.active ? 'pause_circle' : 'play_circle'" :color="o.active ? 'orange-8' : 'green-7'" @click="toggleOrg(o)"><q-tooltip>{{ o.active ? 'Suspend' : 'Activate' }}</q-tooltip></q-btn></td>
                  </tr>
                </tbody>
              </q-markup-table>
            </q-tab-panel>

            <!-- BRANCHES -->
            <q-tab-panel name="branches" class="q-pa-none q-pt-md">
              <div class="row justify-end q-mb-sm"><q-btn unelevated color="amber-8" icon="add" label="New Branch" @click="openBranchCreate" /></div>
              <q-markup-table flat bordered dense class="bg-white" style="max-height:64vh">
                <thead><tr><th class="text-left">Branch</th><th class="text-left">Organization</th><th class="text-center">Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                  <tr v-for="b in branches" :key="b.id" :class="b.deleted_at ? 'text-grey-5' : ''">
                    <td class="text-weight-medium">{{ b.name }}</td><td>{{ b.company?.name_en || '—' }}</td>
                    <td class="text-center"><q-chip dense size="sm" :color="b.deleted_at ? 'blue-grey-5' : (b.active ? 'green-7' : 'orange-8')" text-color="white">{{ b.deleted_at ? 'Archived' : (b.active ? 'Active' : 'Suspended') }}</q-chip></td>
                    <td class="text-right">
                      <q-btn flat dense size="sm" icon="edit" color="blue-8" @click="openRename(b)"><q-tooltip>Rename</q-tooltip></q-btn>
                      <q-btn v-if="!b.deleted_at" flat dense size="sm" :icon="b.active ? 'pause_circle' : 'play_circle'" :color="b.active ? 'orange-8' : 'green-7'" @click="toggleBranch(b)"><q-tooltip>{{ b.active ? 'Suspend' : 'Activate' }}</q-tooltip></q-btn>
                      <q-btn flat dense size="sm" icon="swap_horiz" color="indigo-7" @click="openTransfer(b)"><q-tooltip>Transfer</q-tooltip></q-btn>
                      <q-btn v-if="!b.deleted_at" flat dense size="sm" icon="archive" color="blue-grey-7" @click="archiveBranch(b)"><q-tooltip>Archive</q-tooltip></q-btn>
                      <q-btn flat dense size="sm" icon="delete_forever" color="negative" @click="deleteBranch(b)"><q-tooltip>Delete</q-tooltip></q-btn>
                    </td>
                  </tr>
                </tbody>
              </q-markup-table>
            </q-tab-panel>

            <!-- REQUESTS -->
            <q-tab-panel name="requests" class="q-pa-none q-pt-md">
              <q-markup-table flat bordered dense class="bg-white">
                <thead><tr><th class="text-left">Type</th><th class="text-left">Title</th><th class="text-left">Organization</th><th class="text-center">Status</th><th class="text-right">Decision</th></tr></thead>
                <tbody>
                  <tr v-if="!requests.length"><td colspan="5" class="text-center text-grey-5 q-py-md">No requests.</td></tr>
                  <tr v-for="r in requests" :key="r.id">
                    <td><q-chip dense size="sm" color="blue-grey-7" text-color="white">{{ r.type }}</q-chip></td>
                    <td>{{ r.title }}</td><td>{{ r.company?.name_en || '—' }}</td>
                    <td class="text-center"><q-chip dense size="sm" :color="reqColor(r.status)" text-color="white">{{ r.status }}</q-chip></td>
                    <td class="text-right">
                      <template v-if="r.status === 'pending'">
                        <q-btn flat dense size="sm" icon="check" color="green-7" @click="decide(r, 'approved')"><q-tooltip>Approve</q-tooltip></q-btn>
                        <q-btn flat dense size="sm" icon="close" color="negative" @click="decide(r, 'rejected')"><q-tooltip>Reject</q-tooltip></q-btn>
                        <q-btn flat dense size="sm" icon="help" color="blue-8" @click="decide(r, 'info_requested')"><q-tooltip>Request info</q-tooltip></q-btn>
                        <q-btn flat dense size="sm" icon="schedule" color="indigo-7" @click="decide(r, 'scheduled')"><q-tooltip>Schedule</q-tooltip></q-btn>
                        <q-btn flat dense size="sm" icon="support_agent" color="teal-7" @click="decide(r, 'assigned')"><q-tooltip>Assign to support</q-tooltip></q-btn>
                        <q-btn flat dense size="sm" icon="priority_high" color="deep-orange-8" @click="decide(r, 'escalated')"><q-tooltip>Escalate</q-tooltip></q-btn>
                      </template>
                      <span v-else class="text-caption text-grey-6">by {{ r.decided_by ? 'owner' : '—' }}</span>
                    </td>
                  </tr>
                </tbody>
              </q-markup-table>
            </q-tab-panel>

            <!-- AUDIT -->
            <q-tab-panel name="audit" class="q-pa-none q-pt-md">
              <q-markup-table flat bordered dense class="bg-white" style="max-height:66vh">
                <thead><tr><th class="text-left">When</th><th class="text-left">Actor</th><th class="text-left">Action</th><th class="text-left">Resource</th><th class="text-left">Before → After</th></tr></thead>
                <tbody>
                  <tr v-if="!audit.length"><td colspan="5" class="text-center text-grey-5 q-py-md">No platform actions yet.</td></tr>
                  <tr v-for="a in audit" :key="a.id">
                    <td class="text-caption">{{ fmtDt(a.created_at) }}</td><td class="text-caption">{{ a.actor_email }}</td>
                    <td><q-chip dense size="sm" color="blue-grey-8" text-color="white">{{ a.action }}</q-chip></td>
                    <td class="text-caption">{{ a.resource_type }}<span v-if="a.resource_id"> #{{ a.resource_id }}</span></td>
                    <td class="text-caption" style="max-width:340px"><span class="text-grey-6">{{ short(a.before) }}</span> → <b>{{ short(a.after) }}</b></td>
                  </tr>
                </tbody>
              </q-markup-table>
            </q-tab-panel>
          </q-tab-panels>
        </div>
      </div>
    </m-backgrounds>

    <!-- New organization -->
    <m-modal :showCM="orgDialog" @update:showCM="orgDialog = $event" card_style="width: 480px">
      <q-card class="bg-white"><n-header icon="domain">New Organization</n-header><q-separator />
        <q-form @submit="createOrg"><q-card-section class="row q-col-gutter-sm">
          <div class="col-8"><q-input outlined dense color="primary" v-model="orgForm.name_en" label="Name" :rules="[v => !!v || 'Required']" /></div>
          <div class="col-4"><q-input outlined dense color="primary" v-model="orgForm.abbreviation" label="Abbr" :rules="[v => !!v || 'Required']" /></div>
          <div class="col-6"><q-input outlined dense color="primary" v-model="orgForm.email" label="Email" /></div>
          <div class="col-6"><q-input outlined dense color="primary" v-model="orgForm.phone" label="Phone" /></div>
        </q-card-section><q-separator /><n-submit :submitting="busy" label="Create" /></q-form>
      </q-card>
    </m-modal>

    <!-- New branch / rename / transfer -->
    <m-modal :showCM="branchDialog" @update:showCM="branchDialog = $event" card_style="width: 460px">
      <q-card class="bg-white"><n-header icon="store">{{ branchMode === 'create' ? 'New Branch' : branchMode === 'rename' ? 'Rename Branch' : 'Transfer Branch' }}</n-header><q-separator />
        <q-form @submit="saveBranch"><q-card-section class="row q-col-gutter-sm">
          <div class="col-12" v-if="branchMode !== 'rename'"><q-select outlined dense color="primary" v-model="branchForm.company_id" :options="orgOptions" emit-value map-options label="Organization" :rules="[v => !!v || 'Required']" /></div>
          <div class="col-12" v-if="branchMode !== 'transfer'"><q-input outlined dense color="primary" v-model="branchForm.name" label="Branch name" :rules="[v => !!v || 'Required']" /></div>
        </q-card-section><q-separator /><n-submit :submitting="busy" label="Save" /></q-form>
      </q-card>
    </m-modal>
  </q-page>
</template>

<script setup>
import { ref, reactive, computed, onMounted, getCurrentInstance } from 'vue'
import { Notify, Dialog } from 'quasar'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

const { proxy } = getCurrentInstance()
const auth = useAuthStore()
const ownerEmail = computed(() => auth.user?.email || '')

const tab = ref('dashboard')
const s = ref({ total_organizations: 0, active_organizations: 0, total_branches: 0, active_branches: 0, suspended_branches: 0, archived_branches: 0, new_branches_30d: 0, pending_requests: 0, branch_distribution: [] })
const orgs = ref([])
const branches = ref([])
const requests = ref([])
const audit = ref([])
const busy = ref(false)

const kpis = computed(() => [
  { label: 'Organizations', value: s.value.total_organizations, icon: 'domain', color: '#2563EB', tint: '#DBEAFE' },
  { label: 'Total Branches', value: s.value.total_branches, icon: 'store', color: '#0D9488', tint: '#CCFBF1' },
  { label: 'Active Branches', value: s.value.active_branches, icon: 'check_circle', color: '#16A34A', tint: '#DCFCE7' },
  { label: 'Suspended', value: s.value.suspended_branches, icon: 'pause_circle', color: '#D97706', tint: '#FEF3C7' },
  { label: 'Archived', value: s.value.archived_branches, icon: 'archive', color: '#64748B', tint: '#F1F5F9' },
  { label: 'New (30d)', value: s.value.new_branches_30d, icon: 'trending_up', color: '#7C3AED', tint: '#EDE9FE' },
  { label: 'Pending Requests', value: s.value.pending_requests, icon: 'fact_check', color: '#DC2626', tint: '#FEE2E2' },
  { label: 'Active Orgs', value: s.value.active_organizations, icon: 'verified', color: '#0891B2', tint: '#CFFAFE' },
])

const orgOptions = computed(() => orgs.value.map(o => ({ label: o.name_en, value: o.id })))
function reqColor (v) { return { pending: 'amber-8', approved: 'green-7', rejected: 'red-7', info_requested: 'blue-7', scheduled: 'indigo-7', assigned: 'teal-7', escalated: 'deep-orange-8' }[v] || 'grey' }
function fmtDt (v) { return v ? new Date(v).toLocaleString() : '—' }
function short (o) { if (o == null) return '—'; const str = typeof o === 'string' ? o : JSON.stringify(o); return str.length > 90 ? str.slice(0, 90) + '…' : str }

async function loadDashboard () { const { data } = await api.get('/platform/dashboard'); s.value = data }
async function loadOrgs () { const { data } = await api.get('/platform/organizations'); orgs.value = data || [] }
async function loadBranches () { const { data } = await api.get('/platform/branches'); branches.value = data || [] }
async function loadRequests () { const { data } = await api.get('/platform/requests'); requests.value = data || [] }
async function loadAudit () { const { data } = await api.get('/platform/audit'); audit.value = data || [] }
async function refresh () { await Promise.all([loadDashboard(), loadOrgs(), loadBranches(), loadRequests(), loadAudit()]) }

const ok = (m) => Notify.create({ type: 'positive', position: 'bottom', message: m })
const err = (e) => Notify.create({ type: 'negative', message: e?.response?.data?.message || 'Failed' })

// Organizations
const orgDialog = ref(false)
const orgForm = reactive({ name_en: '', abbreviation: '', email: '', phone: '' })
async function createOrg () { busy.value = true; try { await api.post('/platform/organizations', orgForm); ok('Organization created'); orgDialog.value = false; Object.assign(orgForm, { name_en: '', abbreviation: '', email: '', phone: '' }); refresh() } catch (e) { err(e) } finally { busy.value = false } }
async function toggleOrg (o) { try { await api.put(`/platform/organizations/${o.id}/toggle`); refresh() } catch (e) { err(e) } }
async function setSelfService (o, v) { try { await api.put(`/platform/organizations/${o.id}/self-service`, { enabled: v }); ok(v ? 'Branch self-service enabled' : 'Reserved to Platform Owner'); refresh() } catch (e) { err(e) } }

// Branches
const branchDialog = ref(false)
const branchMode = ref('create')
const branchForm = reactive({ id: null, company_id: null, name: '' })
function openBranchCreate () { branchMode.value = 'create'; Object.assign(branchForm, { id: null, company_id: null, name: '' }); branchDialog.value = true }
function openRename (b) { branchMode.value = 'rename'; Object.assign(branchForm, { id: b.id, company_id: b.company_id, name: b.name }); branchDialog.value = true }
function openTransfer (b) { branchMode.value = 'transfer'; Object.assign(branchForm, { id: b.id, company_id: b.company_id, name: b.name }); branchDialog.value = true }
async function saveBranch () {
  busy.value = true
  try {
    if (branchMode.value === 'create') await api.post('/platform/branches', { company_id: branchForm.company_id, name: branchForm.name })
    else if (branchMode.value === 'rename') await api.put(`/platform/branches/${branchForm.id}/rename`, { name: branchForm.name })
    else await api.put(`/platform/branches/${branchForm.id}/transfer`, { company_id: branchForm.company_id })
    ok('Saved'); branchDialog.value = false; refresh()
  } catch (e) { err(e) } finally { busy.value = false }
}
async function toggleBranch (b) { try { await api.put(`/platform/branches/${b.id}/toggle`); refresh() } catch (e) { err(e) } }
function archiveBranch (b) { Dialog.create({ title: 'Archive branch', message: `Archive "${b.name}"? It can be restored later.`, cancel: true }).onOk(async () => { try { await api.delete(`/platform/branches/${b.id}/archive`); refresh() } catch (e) { err(e) } }) }
function deleteBranch (b) { Dialog.create({ title: 'Delete branch', message: `Permanently delete "${b.name}"? This cannot be undone.`, cancel: true, ok: { color: 'negative', label: 'Delete' } }).onOk(async () => { try { await api.delete(`/platform/branches/${b.id}`); refresh() } catch (e) { err(e) } }) }

// Requests
async function decide (r, decision) { try { await api.put(`/platform/requests/${r.id}/decide`, { decision }); ok('Decision recorded'); refresh() } catch (e) { err(e) } }

onMounted(refresh)
</script>

<style scoped>
.vip-hero { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  background: linear-gradient(135deg, #4C1D0A 0%, #92400E 55%, #B45309 100%); color: #fff; border-radius: 14px; padding: 16px 18px; box-shadow: 0 10px 26px -12px rgba(180,83,9,.6); }
.vip-hero__l { display: flex; align-items: center; gap: 12px; }
.vip-hero__t { font-size: 20px; font-weight: 800; }
.vip-hero__s { font-size: 12px; opacity: .85; }
:deep(.sidebar-item--vip) .sidebar-label { font-weight: 800; }
</style>
