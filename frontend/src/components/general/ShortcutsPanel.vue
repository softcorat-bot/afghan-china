<template>
  <q-dialog v-model="show" maximized transition-show="slide-up" transition-hide="slide-down">
    <q-card :class="$q.dark.isActive ? 'bg-grey-10' : 'bg-grey-1'">
      <q-card-section class="row items-center bg-cyan-7 text-white q-py-sm">
        <q-icon name="keyboard" size="md" class="q-mr-sm" />
        <span class="text-h6">Keyboard Shortcuts</span>
        <q-chip dense outline color="white" label="?" class="q-ml-sm" />
        <q-space />
        <q-btn flat round dense icon="close" v-close-popup />
      </q-card-section>

      <q-card-section class="q-pa-md">
        <div class="row q-col-gutter-md">
          <div class="col-12 col-sm-6 col-md-4" v-for="group in shortcuts" :key="group.title">
            <div class="text-subtitle2 text-weight-bold text-cyan-8 q-mb-sm">{{ group.title }}</div>
            <q-list dense bordered class="my_radio_less">
              <q-item v-for="s in group.items" :key="s.desc" class="q-py-xs">
                <q-item-section>
                  <span class="text-caption">{{ s.desc }}</span>
                </q-item-section>
                <q-item-section side>
                  <div class="row q-gutter-xs">
                    <kbd v-for="k in s.keys" :key="k"
                      style="background:#eee;border:1px solid #bbb;border-bottom:3px solid #bbb;border-radius:4px;padding:1px 7px;font-size:11px;font-family:monospace;color:#333">
                      {{ k }}
                    </kbd>
                  </div>
                </q-item-section>
              </q-item>
            </q-list>
          </div>
        </div>
      </q-card-section>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue'
import { useQuasar } from 'quasar'

const $q = useQuasar()
const show = defineModel({ default: false })

const shortcuts = [
  {
    title: 'Navigation',
    items: [
      { desc: 'Dashboard', keys: ['Alt', 'D'] },
      { desc: 'New Sale', keys: ['Alt', 'S'] },
      { desc: 'New Purchase', keys: ['Alt', 'P'] },
      { desc: 'Customers', keys: ['Alt', 'C'] },
      { desc: 'Reports', keys: ['Alt', 'R'] },
    ]
  },
  {
    title: 'App Controls',
    items: [
      { desc: 'Show Shortcuts', keys: ['?'] },
      { desc: 'Toggle Dark Mode', keys: ['Alt', 'T'] },
      { desc: 'Toggle Fullscreen', keys: ['F11'] },
      { desc: 'Search', keys: ['Ctrl', 'K'] },
      { desc: 'Refresh Page', keys: ['F5'] },
    ]
  },
  {
    title: 'Tables & Export',
    items: [
      { desc: 'Export CSV', keys: ['Ctrl', 'E'] },
      { desc: 'Print', keys: ['Ctrl', 'P'] },
      { desc: 'Fullscreen Table', keys: ['F'] },
      { desc: 'Show/Hide Columns', keys: ['Ctrl', 'Shift', 'H'] },
    ]
  },
  {
    title: 'Forms',
    items: [
      { desc: 'Save / Submit', keys: ['Ctrl', 'S'] },
      { desc: 'Cancel / Close', keys: ['Esc'] },
      { desc: 'Next Field', keys: ['Tab'] },
      { desc: 'Previous Field', keys: ['Shift', 'Tab'] },
    ]
  },
]

function onKey(e) {
  if (e.key === '?' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
    show.value = !show.value
  }
  if (e.altKey && e.key === 't') {
    $q.dark.toggle()
    localStorage.setItem('theme_dark', $q.dark.isActive ? '1' : '0')
  }
}

onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => document.removeEventListener('keydown', onKey))
</script>
