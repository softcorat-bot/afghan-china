<template>
  <q-page>
    <m-backgrounds>
      <div class="row my_radio_less q-pa-sm">
        <div class="col-12">
          <div class="row items-center justify-between">
            <div class="col">
              <m-header icon="notifications" controlRoomButton="false" class="q-mt-xs">
                {{ $t('Notification') }}
              </m-header>
            </div>
            <div class="col-auto q-mt-xs" v-if="unreadCount > 0">
              <progress-btn flat color="primary" icon="done_all" @click="markAll">
                {{ $t('MarkAllRead') }}
              </progress-btn>
            </div>
          </div>
        </div>

        <!-- Filter tabs -->
        <div class="col-12 q-mt-sm">
          <q-btn-toggle v-model="tab" no-caps dense unelevated
            toggle-color="primary" color="grey-2" text-color="grey-7"
            :options="[{label:'All', value:'all'},{label:`Unread (${unreadCount})`, value:'unread'}]" />
        </div>

        <div class="col-12 q-mt-sm">
          <q-card class="my_radio_less">
            <q-card-section class="q-pa-none">
              <q-list separator>
                <q-item v-if="loading">
                  <q-item-section><q-skeleton type="text" /></q-item-section>
                </q-item>
                <q-item v-else-if="visible.length === 0">
                  <q-item-section class="text-grey-5 text-center q-py-xl">
                    <q-icon name="notifications_none" size="40px" class="q-mb-sm" />
                    No notifications
                  </q-item-section>
                </q-item>
                <q-item v-for="n in visible" :key="n.id"
                  :class="n.read_at ? '' : 'bg-blue-1'"
                  class="q-py-md">
                  <q-item-section avatar top>
                    <q-avatar :color="typeColor(n.type)" text-color="white" size="38px">
                      <q-icon :name="typeIcon(n.type)" size="20px" />
                    </q-avatar>
                  </q-item-section>
                  <q-item-section>
                    <q-item-label class="text-weight-medium">{{ n.title ?? n.data?.message ?? '—' }}</q-item-label>
                    <q-item-label caption class="q-mt-xs">{{ n.created_at_human ?? n.created_at?.slice(0,16) }}</q-item-label>
                  </q-item-section>
                  <q-item-section side top>
                    <q-chip v-if="!n.read_at" dense size="sm" color="blue-7" text-color="white">New</q-chip>
                    <q-chip v-else dense size="sm" color="grey-3" text-color="grey-6">Read</q-chip>
                  </q-item-section>
                </q-item>
              </q-list>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 q-mt-xs text-caption text-grey-6">
          {{ rows.length }} notifications total — {{ unreadCount }} unread
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { Notify } from 'quasar'
import { api } from '@/boot/axios'

const rows = ref([])
const loading = ref(false)
const tab = ref('all')

const unreadCount = computed(() => rows.value.filter(n => !n.read_at).length)
const visible = computed(() => tab.value === 'unread' ? rows.value.filter(n => !n.read_at) : rows.value)

function typeIcon(type) {
  const map = { system: 'settings', info: 'info', warning: 'warning' }
  return map[type] ?? 'notifications'
}

function typeColor(type) {
  const map = { system: 'grey-7', info: 'blue', warning: 'amber' }
  return map[type] ?? 'blue-grey'
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/notifications')
    rows.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch (_) {}
  finally { loading.value = false }
}

async function markAll() {
  try {
    await api.post('/notifications/mark-read')
    rows.value = rows.value.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
    Notify.create({ type: 'positive', position: 'bottom', icon: 'done_all', message: 'All marked as read' })
  } catch (_) {}
}

onMounted(load)
</script>
