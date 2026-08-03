<template>
  <!-- Shown at the top of a tab-panel's content. On mobile the tab strip
       only shows icons, so this restates the active section by name. -->
  <div class="tab-title">
    <span class="tab-title__chip" :style="chipStyle">
      <q-icon :name="icon" size="19px" />
    </span>
    <div class="tab-title__text">
      <div class="tab-title__name">
        {{ title }}
        <span v-if="count != null && Number(count) > 0" class="tab-title__count">{{ count }}</span>
      </div>
      <div v-if="subtitle" class="tab-title__sub">{{ subtitle }}</div>
    </div>
    <slot name="actions" />
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: { type: String, default: '' },
  icon: { type: String, default: 'chevron_right' },
  subtitle: { type: String, default: null },
  count: { default: null },
  color: { type: String, default: null }
})

const chipStyle = computed(() => {
  const c = props.color || 'var(--q-primary)'
  return {
    color: c,
    background: `color-mix(in srgb, ${c} 13%, #fff)`
  }
})
</script>

<style scoped>
.tab-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--border-soft, #E7ECF3);
}
.tab-title__chip {
  width: 36px; height: 36px; border-radius: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.tab-title__text { min-width: 0; flex: 1; }
.tab-title__name {
  display: flex; align-items: center; gap: 8px;
  font-size: 17px; font-weight: 800; letter-spacing: -0.2px;
  color: var(--on-surface, #0F172A);
  font-family: poppins, sans-serif;
  line-height: 1.2;
}
.tab-title__count {
  font-size: 11px; font-weight: 800;
  background: color-mix(in srgb, var(--q-primary) 14%, #fff);
  color: var(--q-primary);
  border-radius: 20px; padding: 1px 9px;
}
.tab-title__sub {
  font-size: 12px; color: var(--on-surface-dim, #64748B); margin-top: 2px;
}
@media (prefers-color-scheme: dark) {
  .tab-title__name { color: #E2E8F0; }
  .tab-title { border-bottom-color: #334155; }
}
</style>
