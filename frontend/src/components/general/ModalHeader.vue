<template>
  <div class="n-header-wrap">
    <div
      class="n-header-bar row items-center no-wrap"
      :class="[bg || '', glossy ? 'm-header-glossy' : '']"
      :style="headerStyle"
    >
      <!-- Icon badge -->
      <div class="n-header-icon-wrap" :style="iconWrapStyle">
        <q-icon :name="icon || 'info'" color="white" :size="iconSize || '20px'" />
      </div>

      <!-- Title + subtitle -->
      <div class="col q-ml-sm" style="min-width:0">
        <div class="n-header-title ellipsis">
          <slot></slot>
        </div>
        <div v-if="subtitle" class="n-header-subtitle ellipsis">{{ subtitle }}</div>
      </div>

      <!-- Badge / count -->
      <q-badge v-if="badge" :color="badgeColor || 'white'" :text-color="badgeTextColor || 'primary'"
        :label="badge" class="q-mr-sm" style="font-size:10px" />

      <!-- Right slot for extra buttons -->
      <slot name="actions"></slot>

      <!-- Close button -->
      <q-btn
        dense flat round icon="close"
        color="white"
        size="sm"
        class="q-ma-xs"
        v-close-popup
      >
        <q-tooltip>{{ $t ? $t('Close') : 'Close' }}</q-tooltip>
      </q-btn>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  icon:           { type: String, default: null },
  iconSize:       { type: String, default: null },
  subtitle:       { type: String, default: null },
  badge:          { type: String, default: null },
  badgeColor:     { type: String, default: null },
  badgeTextColor: { type: String, default: null },
  bg:             { type: String, default: null },
  glossy:         { type: Boolean, default: false },
  flat:           { type: Boolean, default: false },   // white background, colored icon/text
  accent:         { type: String, default: null },     // override accent color (hex)
})

const headerStyle = computed(() => {
  if (props.bg) return {}  // Quasar class handles it
  if (props.flat) {
    return {
      background: '#fff',
      borderBottom: `2px solid var(--sidebar-accent, #00acc1)`,
    }
  }
  return {
    background: 'linear-gradient(135deg, var(--topbar-from, #0097a7) 0%, var(--topbar-to, #006064) 100%)'
  }
})

const iconWrapStyle = computed(() => {
  if (props.flat) return { background: `var(--sidebar-accent-bg, #e0f7fa)`, borderRadius: '8px', padding: '4px 8px' }
  return { background: 'rgba(255,255,255,.18)', borderRadius: '8px', padding: '4px 8px' }
})
</script>

<style scoped>
.n-header-wrap { width: 100%; }

.n-header-bar {
  width: 100%;
  padding: 8px 12px;
  min-height: 48px;
  border-radius: 0;
  position: relative;
  overflow: hidden;
}

.m-header-glossy::after {
  content: '';
  position: absolute;
  inset: 0;
  background-image: linear-gradient(to bottom, rgba(255,255,255,.22), rgba(255,255,255,0) 50%, rgba(0,0,0,.08) 51%, rgba(0,0,0,.03));
  pointer-events: none;
}

.n-header-title {
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  font-family: poppins, sans-serif;
  letter-spacing: .2px;
}

.n-header-subtitle {
  color: rgba(255,255,255,.75);
  font-size: 11px;
  margin-top: 1px;
}
</style>
