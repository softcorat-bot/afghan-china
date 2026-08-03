<template>
  <div class="stat-card" :class="{ 'stat-card--dense': dense }" :title="label" :style="`--sc-color:${color};--sc-tint:${tint}`">
    <div class="stat-card__accent"></div>
    <div class="stat-card__head">
      <span class="stat-card__icon"><q-icon :name="icon" :size="dense ? '12px' : '15px'" /></span>
      <span class="stat-card__label">{{ label }}</span>
    </div>
    <div class="stat-card__body">
      <div class="stat-card__value">
        {{ value }}<span v-if="suffix" class="stat-card__suffix">{{ suffix }}</span>
      </div>
      <div class="stat-card__foot" v-if="sub">
        <q-icon v-if="subIcon" :name="subIcon" size="13px" class="q-mr-xs" />
        <span>{{ sub }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  icon: { type: String, required: true },
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  suffix: { type: String, default: '' },
  sub: { type: String, default: '' },
  subIcon: { type: String, default: '' },
  color: { type: String, default: '#175A8C' },
  tint: { type: String, default: '#E0EDF7' },
  dense: { type: Boolean, default: false },
})
</script>

<style scoped>
.stat-card {
  position: relative;
  background: #fff;
  border: 1px solid #E7ECF3;
  border-radius: 12px;
  padding: 9px 12px 8px;
  height: 100%;
  overflow: hidden;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
  /* Dashboard animation language: staggered entrance (delay set globally
     per position in the row) — backwards fill so hover transforms still win
     once the entrance is done. */
  animation: scin 0.5s backwards;
}
@keyframes scin { from { opacity: 0; transform: translateY(10px); } }
.stat-card:hover {
  transform: translateY(-3px);
  border-color: var(--sc-color);
  box-shadow: 0 14px 28px -18px color-mix(in srgb, var(--sc-color) 55%, transparent);
}
/* Shine strip sweeping across on hover */
.stat-card::after {
  content: ''; position: absolute; top: 0; bottom: 0; width: 40px;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.85), transparent);
  inset-inline-start: -60px; transform: skewX(-20deg); pointer-events: none;
}
.stat-card:hover::after { animation: scshine 0.7s ease; }
@keyframes scshine { to { inset-inline-start: 120%; } }
.stat-card:hover .stat-card__icon { transform: scale(1.12) rotate(-6deg); }
.stat-card__accent {
  position: absolute; inset-inline-start: 0; top: 8px; bottom: 8px; width: 3px;
  border-radius: 3px;
  background: linear-gradient(180deg, var(--sc-color), color-mix(in srgb, var(--sc-color) 40%, #fff));
}
.stat-card__head { display: flex; align-items: center; gap: 7px; }
.stat-card__icon {
  width: 27px; height: 27px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  background: var(--sc-tint); color: var(--sc-color);
  flex-shrink: 0;
  transition: transform 0.2s ease;
}
.stat-card__label {
  font-size: 9.5px; font-weight: 700; letter-spacing: 0.05em;
  text-transform: uppercase; color: #8A94A6;
}
.stat-card__value {
  margin-top: 5px;
  font-size: 17px; font-weight: 800; letter-spacing: -0.4px;
  color: #0F172A; line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.stat-card__suffix { font-size: 10.5px; font-weight: 700; color: #94A3B8; margin-inline-start: 4px; letter-spacing: 0; }
.stat-card__body { display: contents; }
.stat-card__foot {
  display: flex; align-items: center;
  margin-top: 3px; font-size: 10px; font-weight: 600; color: var(--sc-color);
}

/* Dense: one seamless row — icon, label, value — for modals and tab headers */
.stat-card--dense {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 9px; border-radius: 9px;
}
.stat-card--dense:hover { transform: none; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
.stat-card--dense:hover .stat-card__icon { transform: none; }
.stat-card--dense .stat-card__accent { top: 5px; bottom: 5px; width: 2.5px; }
.stat-card--dense .stat-card__head { gap: 7px; flex: 1; min-width: 0; }
.stat-card--dense .stat-card__icon { width: 21px; height: 21px; border-radius: 7px; }
/* One line only: a wrapping label was what made these cards tall. */
.stat-card--dense .stat-card__label {
  font-size: 9px; letter-spacing: 0.03em; line-height: 1.25;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.stat-card--dense .stat-card__body { display: flex; flex-direction: column; align-items: flex-end; }
.stat-card--dense .stat-card__value { margin-top: 0; font-size: 13.5px; white-space: nowrap; }
.stat-card--dense .stat-card__suffix { font-size: 9px; margin-inline-start: 3px; }
.stat-card--dense .stat-card__foot {
  margin-top: 0; font-size: 9px; font-weight: 600;
  color: #8A94A6; white-space: nowrap;
}

@media (prefers-color-scheme: dark) {
  .stat-card { background: #1E293B; border-color: #334155; }
  .stat-card__value { color: #F1F5F9; }
}
</style>
