<template>
  <div class="m-header-wrap">
    <div class="m-header-bar row items-center no-wrap">
      <!-- ── Left: back + icon chip + title ── -->
      <div class="row items-center no-wrap col" style="min-width:0">
        <!-- Back button (shown when `back` or `to` is provided) -->
        <q-btn
          v-if="back || to"
          flat round dense
          icon="arrow_back"
          color="grey-8"
          size="sm"
          class="flex-shrink-0"
          @click="goBack"
        >
          <q-tooltip>{{ $t ? $t('Back') : 'Back' }}</q-tooltip>
        </q-btn>

        <!-- Icon chip -->
        <span v-if="icon" class="m-header-chip flex-shrink-0" :class="chipClass">
          <q-icon :name="icon" size="18px" />
        </span>

        <!-- Title block -->
        <div class="col q-ml-sm" style="min-width:0">
          <div class="row items-center no-wrap" style="gap:6px">
            <span class="m-header-title ellipsis"><slot></slot></span>
            <q-badge v-if="badge" :color="badgeColor || 'primary'" :text-color="badgeTextColor || 'white'" :label="badge" style="font-size:10px" />
            <q-chip v-if="count != null" dense :color="countColor || 'blue-grey-2'" :text-color="countTextColor || 'blue-grey-9'" style="font-size:10px;height:18px" :label="String(count)" />
          </div>
          <div v-if="subtitle" class="m-header-subtitle ellipsis">{{ subtitle }}</div>
        </div>
      </div>

      <!-- ── Right: slots + control button ── -->
      <div class="row items-center no-wrap flex-shrink-0" style="gap:4px">
        <slot name="search"></slot>
        <slot name="actions"></slot>

        <q-btn v-if="showRefresh" flat round dense icon="refresh" color="grey-7" size="sm" @click="$emit('refresh')">
          <q-tooltip>{{ $t ? $t('Refresh') : 'Refresh' }}</q-tooltip>
        </q-btn>

        <q-btn
          v-show="controlRoomButton ? false : true"
          :round="!label2"
          color="primary"
          :to="to2"
          :icon="icon2 || 'settings'"
          :label="label2"
          :size="buttonControlSize || 'sm'"
          outline
          class="q-ml-xs"
          @click="$emit('click2')"
        >
          <q-tooltip v-if="btn2Tooltip">{{ btn2Tooltip }}</q-tooltip>
        </q-btn>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  icon: { type: String, default: null },
  to: { type: String, default: null },
  back: { type: Boolean, default: false },
  iconTooltip: { type: String, default: null },
  subtitle: { type: String, default: null },
  badge: { type: String, default: null },
  badgeColor: { type: String, default: null },
  badgeTextColor: { type: String, default: null },
  count: { default: null },
  countColor: { type: String, default: null },
  countTextColor: { type: String, default: null },
  // Retained for backward compatibility (no longer drives the heavy gradient).
  bg: { type: String, default: null },
  glossy: { type: Boolean, default: true },
  flat: { type: Boolean, default: false },
  dark: { type: Boolean, default: false },
  to2: { type: String, default: null },
  icon2: { type: String, default: null },
  label2: { type: String, default: null },
  btn2Tooltip: { type: String, default: null },
  btnTextColor: { type: String, default: null },
  buttonControlSize: { type: String, default: null },
  controlRoomButton: { default: null },
  glossyBtn: { type: Boolean, default: false },
  flatBtn: { type: Boolean, default: false },
  outlineBtn: { type: Boolean, default: false },
  showRefresh: { type: Boolean, default: false },
})

const emit = defineEmits(['click', 'click2', 'refresh'])
const router = useRouter()

function goBack () {
  emit('click')
  if (props.to) router.push(props.to)
  else router.back()
}

// If a Quasar bg class was passed, tint the icon chip with it; else use primary.
const chipClass = computed(() => props.bg && props.bg.startsWith('bg-') ? props.bg + ' text-white' : 'm-header-chip--primary')
</script>

<style scoped>
.m-header-wrap { width: 100%; }
.m-header-bar {
  width: 100%;
  background: var(--surface-card, #fff);
  border: 1px solid var(--border-soft, #E7ECF3);
  border-radius: 12px;
  padding: 8px 12px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.m-header-chip {
  width: 34px; height: 34px; border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
}
.m-header-chip--primary {
  background: color-mix(in srgb, var(--q-primary) 12%, #fff);
  color: var(--q-primary);
}
.m-header-title {
  color: var(--on-surface, #0F172A);
  font-weight: 700;
  font-size: 16px;
  letter-spacing: 0.2px;
  font-family: poppins, sans-serif;
}
.m-header-subtitle {
  color: var(--on-surface-dim, #64748B);
  font-size: 11.5px;
  margin-top: 1px;
}
@media (prefers-color-scheme: dark) {
  .m-header-bar { background: #1E293B; border-color: #334155; }
}
</style>
