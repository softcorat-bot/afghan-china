<template>
  <q-page>
    <m-backgrounds>
      <div class="row q-pa-sm q-col-gutter-sm">
        <div class="col-12">
          <m-header icon="palette" controlRoomButton="false" class="q-mt-xs">
            {{ $t('ThemeAppearance') }}
          </m-header>
        </div>

        <!-- Dark Mode -->
        <div class="col-12 col-sm-6">
          <q-card class="my_radio_less three_d_plan q-pa-md">
            <div class="text-subtitle1 text-weight-bold q-mb-md">
              <q-icon name="brightness_6" class="q-mr-xs" />{{ $t('DisplayMode') }}
            </div>
            <q-btn-toggle v-model="darkMode" spread
              toggle-color="grey-9" color="grey-3" text-color="grey-9"
              :options="[{label:$t('LightMode'),value:false,icon:'light_mode'},{label:$t('DarkMode'),value:true,icon:'dark_mode'}]"
              @update:model-value="applyDark" />
          </q-card>
        </div>

        <!-- Header Color -->
        <div class="col-12 col-sm-6">
          <q-card class="my_radio_less three_d_plan q-pa-md">
            <div class="text-subtitle1 text-weight-bold q-mb-md">
              <q-icon name="color_lens" class="q-mr-xs" />{{ $t('PrimaryColor') }}
            </div>
            <div class="text-caption text-grey-6 q-mb-sm">Header Color</div>
            <div class="row q-gutter-sm items-center">
              <div
                v-for="preset in headerPresets"
                :key="preset.name"
                class="cursor-pointer theme-swatch"
                :style="`background: linear-gradient(135deg, ${preset.from} 0%, ${preset.to} 100%);
                         outline: 3px solid ${activePresetName === preset.name ? '#333' : 'transparent'};
                         outline-offset: 2px`"
                @click="applyThemePreset(preset)"
                :title="preset.name"
              />
            </div>
          </q-card>
        </div>

        <!-- Font Size -->
        <div class="col-12 col-sm-6">
          <q-card class="my_radio_less three_d_plan q-pa-md">
            <div class="text-subtitle1 text-weight-bold q-mb-md">
              <q-icon name="text_fields" class="q-mr-xs" />{{ $t('FontSize') }}
            </div>
            <q-btn-toggle v-model="fontSize" spread
              toggle-color="cyan-7" color="grey-3" text-color="grey-9"
              :options="[{label:$t('Small'),value:'small'},{label:$t('Normal'),value:'normal'},{label:$t('Large'),value:'large'}]"
              @update:model-value="applyFont" />
          </q-card>
        </div>

        <!-- Border Radius -->
        <div class="col-12 col-sm-6">
          <q-card class="my_radio_less three_d_plan q-pa-md">
            <div class="text-subtitle1 text-weight-bold q-mb-md">
              <q-icon name="rounded_corner" class="q-mr-xs" />{{ $t('BorderRadius') }}
            </div>
            <q-btn-toggle v-model="radius" spread
              toggle-color="cyan-7" color="grey-3" text-color="grey-9"
              :options="[{label:$t('Sharp'),value:'2px'},{label:$t('Normal'),value:'8px'},{label:$t('Round'),value:'16px'}]"
              @update:model-value="applyRadius" />
          </q-card>
        </div>

        <!-- Sidebar -->
        <div class="col-12 col-sm-6">
          <q-card class="my_radio_less three_d_plan q-pa-md">
            <div class="text-subtitle1 text-weight-bold q-mb-md">
              <q-icon name="view_sidebar" class="q-mr-xs" />{{ $t('SidebarStyle') }}
            </div>
            <q-btn-toggle v-model="sidebarStyle" spread
              toggle-color="cyan-7" color="grey-3" text-color="grey-9"
              :options="[{label:'Mini',value:'mini'},{label:$t('Normal'),value:'normal'},{label:'Wide',value:'wide'}]"
              @update:model-value="applySidebar" />
          </q-card>
        </div>

        <!-- Reset -->
        <div class="col-12">
          <q-btn outline color="negative" icon="restart_alt" :label="$t('ResetToDefault')" @click="resetAll" />
        </div>
      </div>
    </m-backgrounds>
  </q-page>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useQuasar, Notify } from 'quasar'

const $q = useQuasar()
const darkMode = ref(false)
const fontSize = ref('normal')
const radius = ref('8px')
const sidebarStyle = ref('normal')
const activePresetName = ref('Steel Blue')

// Construction/engineering palette — replaces the generic Material presets.
const headerPresets = [
  { name: 'Steel Blue',   from: '#123A66', to: '#0B1626', accent: '#175A8C', accentBg: '#EAF3FB' },
  { name: 'Amber Gold',   from: '#8A5A1E', to: '#5C3A10', accent: '#C8862D', accentBg: '#FBF0DD' },
  { name: 'Slate',        from: '#334155', to: '#0F172A', accent: '#475569', accentBg: '#F1F5F9' },
  { name: 'Forest Green', from: '#1E4620', to: '#0D2A0E', accent: '#2E7D32', accentBg: '#E8F5E9' },
  { name: 'Brick Red',    from: '#7A2E22', to: '#4A1912', accent: '#B23A2A', accentBg: '#FCE9E6' },
  { name: 'Concrete Grey',from: '#4B5259', to: '#282C30', accent: '#6B7280', accentBg: '#F3F4F6' },
  { name: 'Safety Orange',from: '#8A4A0E', to: '#5C3009', accent: '#E07A1F', accentBg: '#FDF0E1' },
  { name: 'Charcoal',     from: '#1F2937', to: '#0B0F16', accent: '#374151', accentBg: '#F1F2F4' },
  { name: 'Copper',       from: '#7A4B2E', to: '#4A2C1A', accent: '#B87333', accentBg: '#FBEEE1' },
  { name: 'Navy',         from: '#0A1628', to: '#050B14', accent: '#123A66', accentBg: '#E7EEF6' },
]

function applyThemePreset(preset) {
  activePresetName.value = preset.name
  document.documentElement.style.setProperty('--topbar-from', preset.from)
  document.documentElement.style.setProperty('--topbar-to', preset.to)
  document.documentElement.style.setProperty('--sidebar-accent', preset.accent)
  document.documentElement.style.setProperty('--sidebar-accent-bg', preset.accentBg)
  document.documentElement.style.setProperty('--q-primary', preset.accent)
  localStorage.setItem('theme_topbar_from', preset.from)
  localStorage.setItem('theme_topbar_to', preset.to)
  localStorage.setItem('theme_sidebar_accent', preset.accent)
  localStorage.setItem('theme_sidebar_accent_bg', preset.accentBg)
  localStorage.setItem('theme_primary', preset.accent)
  Notify.create({ type: 'positive', position: 'bottom', message: `${preset.name} theme applied` })
}

function applyDark(val) {
  $q.dark.set(val)
  localStorage.setItem('theme_dark', val ? '1' : '0')
  Notify.create({ type: 'positive', position: 'bottom', message: val ? 'Dark mode on' : 'Light mode on' })
}

function applyFont(size) {
  const map = { small: '13px', normal: '14px', large: '16px' }
  document.documentElement.style.fontSize = map[size]
  localStorage.setItem('theme_font', size)
}

function applyRadius(r) {
  document.documentElement.style.setProperty('--my-radius', r)
  localStorage.setItem('theme_radius', r)
}

function applySidebar(s) {
  localStorage.setItem('theme_sidebar', s)
}

function resetAll() {
  localStorage.removeItem('theme_dark')
  localStorage.removeItem('theme_primary')
  localStorage.removeItem('theme_font')
  localStorage.removeItem('theme_radius')
  localStorage.removeItem('theme_sidebar')
  localStorage.removeItem('theme_topbar_from')
  localStorage.removeItem('theme_topbar_to')
  localStorage.removeItem('theme_sidebar_accent')
  localStorage.removeItem('theme_sidebar_accent_bg')

  darkMode.value = false
  fontSize.value = 'normal'
  radius.value = '8px'
  sidebarStyle.value = 'normal'
  activePresetName.value = 'Steel Blue'

  $q.dark.set(false)
  document.documentElement.style.removeProperty('--q-primary')
  document.documentElement.style.fontSize = '14px'
  document.documentElement.style.removeProperty('--my-radius')
  document.documentElement.style.setProperty('--topbar-from', '#123A66')
  document.documentElement.style.setProperty('--topbar-to', '#0B1626')
  document.documentElement.style.setProperty('--sidebar-accent', '#C8862D')
  document.documentElement.style.setProperty('--sidebar-accent-bg', '#FBF0DD')

  Notify.create({ type: 'positive', position: 'bottom', message: 'Theme reset to default' })
}

onMounted(() => {
  darkMode.value = localStorage.getItem('theme_dark') === '1'
  fontSize.value = localStorage.getItem('theme_font') || 'normal'
  radius.value = localStorage.getItem('theme_radius') || '8px'
  sidebarStyle.value = localStorage.getItem('theme_sidebar') || 'normal'

  const savedFrom = localStorage.getItem('theme_topbar_from')
  if (savedFrom) {
    const match = headerPresets.find(p => p.from === savedFrom)
    if (match) activePresetName.value = match.name
  }
})
</script>

<style scoped>
.theme-swatch {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  transition: transform .15s;
}
.theme-swatch:hover {
  transform: scale(1.12);
}
</style>
