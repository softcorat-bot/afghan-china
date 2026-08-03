<template>
  <q-layout view="hHh LpR fFf">
    <!-- ─── TOP BAR ─────────────────────────────────────────────── -->
    <q-header v-if="!ui.posKiosk" class="topbar-header">
      <q-toolbar class="q-px-md" style="min-height:48px">

        <!-- Hamburger -->
        <q-btn flat dense round icon="menu" color="white" aria-label="Menu"
          @click="leftDrawerOpen = !leftDrawerOpen" />

        <!-- Company avatar + Brand text + Breadcrumb -->
        <div class="row items-center no-wrap q-ml-xs" style="gap:8px; overflow:hidden">
          <q-avatar color="white" text-color="primary" size="28px"
            style="font-size:13px; font-weight:700; flex-shrink:0">
            {{ (company.abbreviation || companyName || 'A').charAt(0).toUpperCase() }}
          </q-avatar>
          <div class="row items-center no-wrap" style="gap:4px; overflow:hidden">
            <span class="text-white text-weight-bold ellipsis mobile-hide" style="font-size:15px; letter-spacing:.3px">
              {{ companyName }}
            </span>
            <span v-if="routeLabel" class="text-white mobile-hide" style="opacity:.55; font-size:13px">/</span>
            <span v-if="routeLabel" class="text-white ellipsis mobile-hide" style="font-size:13px; opacity:.85; max-width:160px">
              {{ routeLabel }}
            </span>
          </div>
        </div>

        <q-space />

        <!-- Global search -->
        <q-btn flat dense round size="sm" color="white" icon="search" class="q-mx-xs" @click="openSearch">
          <q-tooltip>{{ $t('Search') }}</q-tooltip>
        </q-btn>

        <!-- Fullscreen (desktop only) -->
        <q-btn flat dense round size="sm" color="white" class="mobile-hide"
          :icon="$q.fullscreen.isActive ? 'fullscreen_exit' : 'fullscreen'"
          @click="$q.fullscreen.toggle()">
          <q-tooltip>Fullscreen</q-tooltip>
        </q-btn>

        <!-- Notifications -->
        <q-btn v-if="$can('notification-list')" flat round dense icon="notifications" color="white" class="q-mx-xs">
          <q-badge v-if="unreadCount > 0" color="red" :label="unreadCount" floating />
          <q-tooltip>{{ $t('Notification') }}</q-tooltip>
          <q-menu style="width:340px; max-height:500px">
            <q-card class="my_radio_less">
              <q-bar class="bg-cyan-7 text-white">
                <q-icon name="notifications" /><span class="q-ml-sm text-weight-bold">{{ $t('Notification') }}</span>
                <q-space />
                <q-btn flat dense size="sm" :label="$t('MarkAllRead')" @click="markAllRead" />
              </q-bar>
              <q-scroll-area style="height:400px">
                <q-list separator>
                  <q-item v-if="notifications.length === 0" class="q-py-md">
                    <q-item-section class="text-center text-grey-6">No notifications</q-item-section>
                  </q-item>
                  <q-item v-for="n in notifications" :key="n.id" clickable
                    :class="n.read_at ? '' : 'bg-cyan-1'">
                    <q-item-section avatar>
                      <q-icon :name="notifIcon(n.type)" :color="notifColor(n.type)" />
                    </q-item-section>
                    <q-item-section>
                      <q-item-label class="text-weight-medium">{{ n.title }}</q-item-label>
                      <q-item-label caption>{{ n.body }}</q-item-label>
                      <q-item-label caption class="text-grey-5">{{ n.created_at_human }}</q-item-label>
                    </q-item-section>
                    <q-item-section side v-if="!n.read_at">
                      <q-badge color="cyan-6" label="New" />
                    </q-item-section>
                  </q-item>
                </q-list>
              </q-scroll-area>
            </q-card>
          </q-menu>
        </q-btn>


        <!-- Language (each language can be hidden per role) -->
        <q-btn-dropdown v-if="$can('language-list')" flat dense icon="language" :label="currentLang" size="sm" color="white" class="q-mx-xs">
          <q-list dense>
            <q-item v-if="$can('lang-en-list')" clickable v-close-popup @click="setLang('en')"><q-item-section>English</q-item-section></q-item>
            <q-item v-if="$can('lang-fa-list')" clickable v-close-popup @click="setLang('fa')"><q-item-section>فارسی</q-item-section></q-item>
            <q-item v-if="$can('lang-pa-list')" clickable v-close-popup @click="setLang('pa')"><q-item-section>پښتو</q-item-section></q-item>
            <q-item v-if="$can('lang-zh-list')" clickable v-close-popup @click="setLang('zh')"><q-item-section>中文</q-item-section></q-item>
          </q-list>
        </q-btn-dropdown>

        <!-- User avatar -->
        <q-btn flat round color="white" icon="account_circle" class="q-ml-xs">
          <q-menu>
            <q-card style="width:300px">
              <div class="bg-white my_card">
                <q-list>
                  <q-item>
                    <q-item-section avatar>
                      <q-avatar color="cyan-6" text-color="white" size="38px">
                        {{ (company.abbreviation || companyName || 'A').charAt(0).toUpperCase() }}
                      </q-avatar>
                    </q-item-section>
                    <q-item-section>
                      <q-item-label class="text-weight-bold">{{ company.abbreviation || companyName }}</q-item-label>
                    </q-item-section>
                    <q-item-section side>
                      <q-btn icon="mdi-logout" round flat dense color="grey-7" @click="logout">
                        <q-tooltip>{{ $t('LogOut') }}</q-tooltip>
                      </q-btn>
                    </q-item-section>
                  </q-item>
                </q-list>

                <!-- Branch switcher — lives only here, in the profile menu -->
                <q-separator />
                <div class="q-px-sm q-pt-sm">
                  <div class="text-caption text-grey-6 q-mb-xs"><q-icon name="store" size="14px" class="q-mr-xs" />{{ $t('Branch') }}</div>
                  <q-list dense class="branch-pick">
                    <q-item v-if="seesAllBranches" clickable v-ripple v-close-popup
                      class="rounded-borders" :active="!activeBranch" active-class="branch-option--active" @click="selectBranch('all')">
                      <q-item-section avatar style="min-width:28px"><q-icon name="public" size="16px" :color="!activeBranch ? 'cyan-7' : 'grey-5'" /></q-item-section>
                      <q-item-section>{{ $t('AllBranches') }}</q-item-section>
                    </q-item>
                    <q-item v-for="b in branches" :key="b.id" clickable v-ripple v-close-popup
                      class="rounded-borders" :active="activeBranch === b.id" active-class="branch-option--active" @click="selectBranch(b.id)">
                      <q-item-section avatar style="min-width:28px"><q-icon name="place" size="16px" :color="activeBranch === b.id ? 'cyan-7' : 'grey-5'" /></q-item-section>
                      <q-item-section>{{ b.name }}</q-item-section>
                    </q-item>
                  </q-list>
                </div>

                <q-separator />
                <div class="q-pa-xs q-pb-sm">
                  <q-list dense>
                    <q-item clickable v-ripple class="rounded-borders text-negative" @click="logout">
                      <q-item-section avatar><q-icon name="mdi-logout" color="negative" /></q-item-section>
                      <q-item-section class="text-negative">{{ $t('LogOut') }}</q-item-section>
                    </q-item>
                  </q-list>
                </div>
              </div>
            </q-card>
          </q-menu>
        </q-btn>

        <!-- Clock -->
        <div class="text-caption text-white q-ml-sm mobile-hide" style="opacity:.75; min-width:72px; text-align:right">
          {{ clock }}
        </div>
      </q-toolbar>
    </q-header>

    <!-- ─── SIDE DRAWER ─────────────────────────────────────────── -->
    <q-drawer
      v-if="!ui.posKiosk"
      v-model="leftDrawerOpen"
      show-if-above
      :width="262"
      :breakpoint="600"
      class="sidebar-drawer"
    >
      <!-- Drawer header / brand -->
      <div class="sidebar-brand q-px-md q-pt-md q-pb-sm">
        <div class="row items-center no-wrap">
          <brand-mark size="30" class="q-mr-sm" />
          <div class="col ellipsis">
            <div class="text-weight-bold text-grey-9 ellipsis" style="font-size:14px; line-height:1.2">
              {{ companyName }}
            </div>
            <div class="sidebar-brand-subtitle" style="line-height:1.2; font-size:12px">Shopping Center</div>
          </div>
        </div>
      </div>

      <!-- Search -->
      <div class="q-px-sm q-pb-sm">
        <q-input
          outlined
          dense
          clearable
          debounce="200"
          :placeholder="$t('Search') + '...'"
          v-model="searchMenuItems"
          color="cyan-7"
          bg-color="grey-1"
          style="border-radius:8px"
        >
          <template v-slot:prepend><q-icon name="search" color="grey-5" size="18px" /></template>
        </q-input>
      </div>


      <q-separator class="q-mb-xs" />

      <!-- Menu List -->
      <q-scroll-area style="height: calc(100vh - 155px)">
        <q-list class="q-px-xs q-pb-md" style="font-family: poppins, sans-serif">
          <template v-for="m in showMenus" :key="m.name">

            <!-- ── Top-level single link ── -->
            <template v-if="m.url != null">
              <q-item
                v-if="((m.platform && auth.isPlatformOwner) || (m.mainCost && auth.canMainCost) || (!m.platform && !m.mainCost && $can(m.permission))) && m.name !== 'Logout'"
                :to="m.url"
                clickable v-ripple
                class="sidebar-item q-mb-xs"
                :class="[m.url === $route.path ? 'sidebar-item--active' : '', (m.platform || m.mainCost) ? 'sidebar-item--vip' : '']"
              >
                <q-item-section avatar style="min-width:36px">
                  <q-icon :name="m.icon" size="20px"
                    :color="(m.platform || m.mainCost) ? 'amber-6' : (m.url === $route.path ? 'var(--sidebar-accent, #C8862D)' : 'grey-6')" />
                </q-item-section>
                <q-item-section class="sidebar-label">{{ $t(m.name) }}</q-item-section>
              </q-item>

              <q-item
                v-else-if="m.name === 'Logout'"
                clickable v-ripple
                class="sidebar-item sidebar-item--danger q-mb-xs"
                @click="logout"
              >
                <q-item-section avatar style="min-width:36px">
                  <q-icon :name="m.icon" size="20px" color="negative" />
                </q-item-section>
                <q-item-section class="sidebar-label text-negative">{{ $t(m.name) }}</q-item-section>
              </q-item>
            </template>

            <!-- ── Group with submenu ── -->
            <template v-else>
              <q-expansion-item
                group="sidebarGroup"
                expand-separator
                :icon="m.icon"
                :label="$t(m.name)"
                header-class="sidebar-group-header q-mb-xs"
                expand-icon-class="sidebar-expand-icon"
              >
                <q-list class="q-pl-sm">
                  <template v-for="sub in m.is_sub" :key="m.name + '/' + sub.name">
                    <q-item
                      v-if="sub.superAdmin ? auth.isSuperAdmin : $can(sub.permission)"
                      clickable dense v-ripple
                      :to="sub.url"
                      class="sidebar-sub-item q-mb-xs"
                      :class="sub.url === $route.path ? 'sidebar-sub-item--active' : ''"
                    >
                      <q-item-section avatar style="min-width:30px">
                        <q-icon :name="sub.icon" size="16px"
                          :color="sub.url === $route.path ? 'var(--sidebar-accent, #C8862D)' : 'grey-5'" />
                      </q-item-section>
                      <q-item-section class="sidebar-sublabel">{{ $t(sub.name) }}</q-item-section>
                      <q-item-section side v-if="sub.add_url">
                        <q-btn icon="add"
                          :color="sub.url === $route.path ? 'var(--sidebar-accent, #C8862D)' : 'grey-5'"
                          size="xs" :to="sub.add_url" round flat dense>
                          <q-tooltip>Add new</q-tooltip>
                        </q-btn>
                      </q-item-section>
                    </q-item>
                  </template>
                </q-list>
              </q-expansion-item>
            </template>

          </template>
        </q-list>
      </q-scroll-area>
    </q-drawer>

    <!-- Global search palette -->
    <q-dialog v-model="searchOpen" position="top" @hide="searchQuery = ''">
      <q-card class="gsearch">
        <q-input ref="searchInput" v-model="searchQuery" borderless autofocus :placeholder="$t('SearchEverything')" @update:model-value="runSearch" class="gsearch__input">
          <template #prepend><q-icon name="search" color="primary" /></template>
          <template #append>
            <q-spinner v-if="searchLoading" color="primary" size="18px" />
            <q-icon v-else-if="searchQuery" name="close" class="cursor-pointer" @click="searchQuery = ''; searchGroups = []" />
          </template>
        </q-input>
        <q-separator />
        <q-card-section class="gsearch__body">
          <div v-if="searchQuery.length < 2" class="text-caption text-grey-6 text-center q-py-md">{{ $t('TypeToSearch') }}</div>
          <div v-else-if="!searchGroups.length && !searchLoading" class="text-caption text-grey-6 text-center q-py-md">{{ $t('NoRecordFound') }}</div>
          <div v-for="g in searchGroups" :key="g.type" class="q-mb-sm">
            <div class="gsearch__group"><q-icon :name="g.icon" size="14px" class="q-mr-xs" />{{ $t(g.type) }}</div>
            <q-list dense>
              <q-item v-for="(it, i) in g.items" :key="i" clickable v-ripple class="rounded-borders" @click="goSearch(it.to)">
                <q-item-section><q-item-label>{{ it.label }}</q-item-label><q-item-label caption>{{ it.sub }}</q-item-label></q-item-section>
                <q-item-section side><q-icon name="arrow_forward" size="15px" color="grey-5" /></q-item-section>
              </q-item>
            </q-list>
          </div>
        </q-card-section>
      </q-card>
    </q-dialog>

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, getCurrentInstance } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Notify } from 'quasar'
import { menus } from './menus.js'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { i18n } from '@/boot/i18n'
import { api } from '@/boot/axios'
import BrandMark from '@/components/general/BrandMark.vue'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const route = useRoute()
const { proxy } = getCurrentInstance()

const searchMenuItems = ref('')
const leftDrawerOpen = ref(false)
const clock = ref(null)
const company = ref({ abbreviation: null, name_en: null })

const companyName = computed(() =>
  auth.currentCompany?.name_en || company.value.name_en || 'Afghan China MIS'
)

const routeLabel = computed(() =>
  route.meta?.title ||
  route.name?.toString().replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) ||
  ''
)

const showMenus = computed(() => {
  let allm = JSON.parse(JSON.stringify(menus))
  const can = (p) => proxy.$can(p)

  allm.forEach((e) => {
    if (e.platform) {
      // VIP Control Center — visible only to the immutable Platform Owner,
      // never through roles/permissions.
      e.status = auth.isPlatformOwner
    } else if (e.mainCost) {
      // Main Cost — Platform Owner or an explicit 'main-cost' grant.
      e.status = auth.canMainCost
    } else if (e.is_sub.length > 0) {
      e.is_sub.forEach((e2) => {
        if (e2.superAdmin) e2.status = auth.isSuperAdmin
        else if (!can(e2.permission)) e2.status = false
      })
      if (e.is_sub.filter((v) => v.status).length === 0) e.status = false
    } else if (!can(e.permission)) {
      e.status = false
    }
  })

  allm = allm.filter((v) => v.status)

  const needle = searchMenuItems.value
  if (needle) {
    const up = needle.toUpperCase()
    allm = allm.filter((m) => {
      if (m.is_sub.length > 0) {
        m.is_sub = m.is_sub.filter((sub) => sub.name.toUpperCase().includes(up))
      }
      return m.is_sub.length > 0 || m.name.toUpperCase().includes(up)
    })
  }
  return allm
})

const currentLang = computed(() => ({ en: 'EN', fa: 'فا', pa: 'پښ', zh: '中文' })[i18n.locale] || 'EN')
function setLang(lang) {
  i18n.locale = lang
  const dir = ['fa', 'pa'].includes(lang) ? 'rtl' : 'ltr'
  document.documentElement.dir = dir
  document.body.dir = dir
  document.documentElement.lang = lang
}


const notifications = ref([])
const unreadCount = computed(() => notifications.value.filter(n => !n.read_at).length)

function notifIcon(type) {
  const m = { system: 'settings', info: 'info' }
  return m[type] || 'notifications'
}
function notifColor(type) {
  const m = { system: 'grey-7', info: 'cyan-7' }
  return m[type] || 'grey-7'
}
async function loadNotifications() {
  try {
    const { data } = await api.get('/notifications')
    notifications.value = data
  } catch (_) {}
}
async function markAllRead() {
  try {
    await api.post('/notifications/mark-read')
    notifications.value.forEach(n => n.read_at = new Date().toISOString())
  } catch (_) {}
}

// ── Global search ──
const searchOpen = ref(false)
const searchQuery = ref('')
const searchGroups = ref([])
const searchLoading = ref(false)
let searchTimer = null
function openSearch () { searchOpen.value = true }
function runSearch (q) {
  clearTimeout(searchTimer)
  if (!q || q.length < 2) { searchGroups.value = []; searchLoading.value = false; return }
  searchLoading.value = true
  searchTimer = setTimeout(async () => {
    try { const { data } = await api.get('/search', { params: { q } }); searchGroups.value = data.groups || [] }
    catch (_) { searchGroups.value = [] } finally { searchLoading.value = false }
  }, 250)
}
function goSearch (to) { searchOpen.value = false; searchQuery.value = ''; searchGroups.value = []; router.push(to) }

const branches = ref([])
const seesAllBranches = ref(false)
const activeBranch = ref(null) // numeric branch id, or null = all branches

async function loadBranches() {
  try {
    const { data } = await api.get('/user')
    branches.value = data.branches || []
    seesAllBranches.value = !!data.sees_all_branches
    const saved = localStorage.getItem('active_branch')
    if (saved === 'all') activeBranch.value = null
    else if (saved) activeBranch.value = Number(saved)
    else activeBranch.value = data.current_branch || null
    // Keep the axios header in sync with the resolved branch.
    api.setBranch(activeBranch.value === null ? 'all' : activeBranch.value)
  } catch {}
}

async function selectBranch(id) {
  const branchId = id === 'all' ? null : id
  if (branchId === activeBranch.value) return
  api.setBranch(branchId === null ? 'all' : branchId)
  try { await api.post('/me/branch', { branch_id: branchId }) } catch {}
  // Reload so every view refetches under the newly selected branch.
  window.location.reload()
}

let clockTimer = null
let notifTimer = null
onMounted(() => {
  clockTimer = setInterval(() => { clock.value = new Date().toLocaleTimeString() }, 1000)
  if (!auth.user) auth.fetchUser()
  const savedLang = localStorage.getItem('locale') || 'en'
  const dir = ['fa', 'pa'].includes(savedLang) ? 'rtl' : 'ltr'
  document.documentElement.dir = dir
  document.body.dir = dir
  document.documentElement.lang = savedLang
  loadBranches()
  loadNotifications()
  notifTimer = setInterval(loadNotifications, 60000)
})
onBeforeUnmount(() => {
  if (clockTimer) clearInterval(clockTimer)
  if (notifTimer) clearInterval(notifTimer)
})

async function logout() {
  await auth.logout()
  router.push('/login')
}
</script>

<style lang="scss">
.gsearch { width: 640px; max-width: 96vw; border-radius: 14px; overflow: hidden; margin-top: 8vh; }
.gsearch__input { padding: 6px 14px; font-size: 16px; }
.gsearch__body { max-height: 60vh; overflow-y: auto; }
.gsearch__group { font-size: 11px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94A3B8; padding: 6px 6px 2px; }

/* ── Top bar ─────────────────────────────────────── */
.topbar-header {
  background: linear-gradient(135deg, var(--topbar-from, #123A66) 0%, var(--topbar-to, #0B1626) 100%);
  box-shadow: 0 2px 12px rgba(0,0,0,.2);
  border-bottom: 1px solid rgba(255,255,255,.15);
}

/* ── Drawer ──────────────────────────────────────── */
.sidebar-drawer {
  background: #ffffff;
  border-right: 1px solid #e8eaf0;
}

.sidebar-brand {
  background: linear-gradient(135deg, var(--sidebar-accent-bg, #FBF0DD) 0%, #f5f5f5 100%);
  border-bottom: 1px solid #e8eaf0;
}

.sidebar-brand-subtitle {
  color: var(--sidebar-accent, #C8862D);
}

/* ── Branch switcher ─────────────────────────────── */
.branch-switcher {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 20px;
  background: #f5f5f5;
  border: 1px solid #e0e0e0;
  cursor: pointer;
  transition: background .15s;
  user-select: none;

  &:hover {
    background: var(--sidebar-accent-bg, #FBF0DD);
    border-color: var(--sidebar-accent, #C8862D);
  }

  .branch-switcher-icon {
    color: var(--sidebar-accent, #C8862D);
    flex-shrink: 0;
  }

  .branch-name {
    flex: 1;
    font-size: 12.5px;
    font-weight: 500;
    color: #546e7a;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.branch-option--active {
  background: var(--sidebar-accent-bg, #FBF0DD) !important;
  color: var(--sidebar-accent, #C8862D) !important;
  font-weight: 600;
}

/* ── Menu items ──────────────────────────────────── */
.sidebar-item {
  border-radius: 8px !important;
  min-height: 40px;
  padding: 0 10px;
  transition: background .15s, color .15s;
  color: #546e7a;

  &:hover {
    background: var(--sidebar-accent-bg, #FBF0DD) !important;
    color: var(--sidebar-accent, #C8862D);
  }

  &--active {
    background: var(--sidebar-accent-bg, #FBF0DD) !important;
    color: var(--sidebar-accent, #C8862D) !important;
    font-weight: 600;
    border-left: 3px solid var(--sidebar-accent, #C8862D);
    padding-left: 7px;
  }

  &--danger:hover {
    background: #ffebee !important;
  }
}

.sidebar-label {
  font-size: 13px;
  font-weight: 500;
}

/* ── Group headers ───────────────────────────────── */
.sidebar-group-header {
  border-radius: 8px !important;
  min-height: 40px;
  padding: 0 10px;
  color: #37474f !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  transition: background .15s;

  .q-icon { color: #607d8b !important; }

  &:hover {
    background: #eceff1 !important;
  }

  &.q-expansion-item--expanded {
    background: #eceff1 !important;
    color: var(--sidebar-accent, #C8862D) !important;
    .q-icon { color: var(--sidebar-accent, #C8862D) !important; }
  }
}

/* ── Sub items ───────────────────────────────────── */
.sidebar-sub-item {
  border-radius: 6px !important;
  min-height: 34px;
  padding: 0 8px;
  color: #607d8b;
  transition: background .15s, color .15s;

  &:hover {
    background: var(--sidebar-accent-bg, #FBF0DD) !important;
    color: var(--sidebar-accent, #C8862D);
  }

  &--active {
    background: var(--sidebar-accent-bg, #FBF0DD) !important;
    color: var(--sidebar-accent, #C8862D) !important;
    font-weight: 600;
    border-left: 3px solid var(--sidebar-accent, #C8862D);
    padding-left: 5px;
  }
}

.sidebar-sublabel {
  font-size: 12.5px;
}

.sidebar-expand-icon {
  color: #90a4ae !important;
  font-size: 18px;
}

/* ── Expansion open accent ───────────────────────── */
.q-expansion-item--expanded > .q-expansion-item__container > .sidebar-group-header {
  background: var(--sidebar-accent-bg, #FBF0DD) !important;
  color: var(--sidebar-accent, #C8862D) !important;
  .q-icon { color: var(--sidebar-accent, #C8862D) !important; }
}

/* ── Responsive ──────────────────────────────────── */
@media (max-width: 600px) {
  .mobile-hide { display: none !important; }
}
</style>
