<template>
  <component
    :is="to ? 'router-link' : 'button'"
    :to="to || undefined"
    :type="to ? undefined : 'button'"
    class="acb"
    :class="[
      `acb--${variant}`,
      busy ? 'acb--busy' : '',
      dense ? 'acb--dense' : '',
      hasLabel ? '' : 'acb--bare',
    ]"
    :style="tint"
    :disabled="to ? undefined : (disable || busy)"
    :aria-disabled="(disable || busy) ? 'true' : 'false'"
    :aria-busy="busy ? 'true' : 'false'"
    @click="onClick"
  >
    <!-- the gem: a small key cap in the accent colour, holding the icon -->
    <span class="acb__gem">
      <span v-if="busy" class="acb__ring" aria-hidden="true"></span>
      <q-icon v-else :name="icon || 'bolt'" :size="dense ? '14px' : '16px'" />
    </span>

    <span v-if="hasLabel" class="acb__label"><slot /></span>

  </component>
</template>

<script setup>
import { computed, useSlots } from 'vue'

/**
 * The project's action button. Light and airy so it reads on any surface, with
 * one signature detail: the icon sits in a small round accent **gem** on the
 * leading edge. The body keeps a single uniform radius on all four corners.
 *
 * Every derived colour is computed HERE, in JS, and handed over as a plain
 * rgb()/rgba() custom property. That is deliberate: the previous version used
 * CSS `color-mix()`, which browsers without support drop *silently* — taking
 * a button's background, text colour and border with it, which is how a button
 * ends up invisible on somebody's machine. Plain rgb() cannot fail.
 *
 * Registered globally as <ac-btn> and, for pages ported from the legacy app,
 * as <progress-btn>.
 */
const props = defineProps({
  icon: { type: String, default: '' },
  color: { type: String, default: '' },
  to: { type: [String, Object], default: null },
  loading: { type: Boolean, default: false },
  // legacy alias from the old progress-knob button
  indeterminate: { type: Boolean, default: false },
  disable: { type: Boolean, default: false },
  /** Quiet tertiary: no wash or border until hover. */
  flat: { type: Boolean, default: false },
  /** Filled accent, for the one action that should dominate a screen. */
  solid: { type: Boolean, default: false },
  dense: { type: Boolean, default: false },
  // accepted and ignored — the old knob props, so ported call sites stay quiet
  name: { type: [Number, String], default: null },
  size: { type: String, default: '' },
  thickness: { type: [Number, String], default: null },
  label_class: { type: String, default: '' },
})

const emit = defineEmits(['click'])
const slots = useSlots()

const hasLabel = computed(() => !!slots.default)
const busy = computed(() => props.loading || props.indeterminate)
const variant = computed(() => props.solid ? 'solid' : (props.flat ? 'quiet' : 'light'))

/**
 * The house accent is the shopping-centre blue. Quasar colour names that call
 * sites already pass keep working, mapped onto one saturation band so no single
 * button can shout louder than the rest.
 */
const PALETTE = {
  primary: '#1F6FB2', secondary: '#12A594', accent: '#E97C2B',
  positive: '#1B9E5A', negative: '#D6483C', warning: '#DE9A12', info: '#1F6FB2',
  teal: '#12A594', 'teal-7': '#0E8C7E', 'teal-8': '#0B7A6D',
  green: '#1B9E5A', 'green-7': '#178A4E', 'green-8': '#137742',
  red: '#D6483C', 'red-7': '#C43B30', 'red-8': '#AE3327',
  pink: '#CE4E85', 'pink-6': '#CE4E85',
  cyan: '#1592B0', 'cyan-8': '#117B95',
  indigo: '#4054AE', 'blue-grey-9': '#3B5268', 'blue-grey': '#5A7185',
  amber: '#DE9A12', 'amber-8': '#C8860B',
  brown: '#8A6244', purple: '#7B4FBB',
  'deep-orange': '#E97C2B', 'deep-orange-8': '#D46A1E',
  black: '#1B3A5C', grey: '#5B6B7F', 'grey-7': '#5B6B7F',
}
const FALLBACK = '#1F6FB2'
const INK = [11, 39, 67]        // #0B2743 — the label ink to blend toward

function toRgb (hex) {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim())
  if (!m) return null
  let h = m[1]
  if (h.length === 3) h = h.split('').map(c => c + c).join('')
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)]
}
/** Blend two rgb triples; `t` is how much of `a` survives. */
const blend = (a, b, t) => a.map((v, i) => Math.round(v * t + b[i] * (1 - t)))
const rgb = (c) => `rgb(${c[0]}, ${c[1]}, ${c[2]})`
const rgba = (c, a) => `rgba(${c[0]}, ${c[1]}, ${c[2]}, ${a})`

const accentRgb = computed(() => toRgb(PALETTE[props.color]
  || PALETTE[String(props.color).replace(/-\d+$/, '')]
  || props.color) || toRgb(FALLBACK))

/**
 * Every colour the button needs, as plain rgb()/rgba() — no CSS colour
 * functions, so nothing is ever dropped by an older engine.
 */
const tint = computed(() => {
  const a = accentRgb.value
  return {
    '--acb': rgb(a),
    '--acb-wash': rgba(a, 0.07),
    '--acb-wash-hi': rgba(a, 0.13),
    '--acb-edge': rgba(a, 0.34),
    '--acb-edge-hi': rgba(a, 0.58),
    '--acb-ink': rgb(blend(a, INK, 0.28)),        // tinted, but still dark enough to read
    '--acb-gem-top': rgb(blend(a, [255, 255, 255], 0.86)),
    '--acb-gem-bot': rgb(blend(a, [6, 18, 31], 0.8)),
    '--acb-glow': rgba(a, 0.3),
    '--acb-shadow': rgba(blend(a, [6, 18, 31], 0.4), 0.32),
  }
})

function onClick (e) {
  if (props.disable || busy.value) { e?.preventDefault?.(); return }
  emit('click', e)
}
</script>

<style scoped>
.acb {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 14px 4px 5px;
  margin: 3px 4px;
  border: 1px solid transparent;
  /* One uniform radius on all four corners, in every variant and both text
     directions — the asymmetric version read as a mistake rather than a
     signature. The gem carries the character instead. */
  border-radius: 16px;
  font: inherit;
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: 0.01em;
  line-height: 1.3;
  text-decoration: none;
  cursor: pointer;
  overflow: hidden;
  -webkit-tap-highlight-color: transparent;
  transition: transform 0.15s ease, box-shadow 0.2s ease,
              background-color 0.18s ease, border-color 0.18s ease;
}
.acb--dense { padding: 2px 11px 2px 4px; font-size: 11.5px; gap: 6px; }

/* ── light: the default. A pale accent wash on white, always legible. ── */
.acb--light {
  background-color: #FFFFFF;
  background-image: linear-gradient(180deg, var(--acb-wash), rgba(255, 255, 255, 0));
  border-color: var(--acb-edge);
  color: var(--acb-ink);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}
.acb--light:hover {
  background-color: var(--acb-wash);
  border-color: var(--acb-edge-hi);
  transform: translateY(-1px);
  box-shadow: 0 7px 14px -8px var(--acb-shadow);
}
.acb--light:active { transform: translateY(1px); box-shadow: inset 0 2px 4px rgba(15, 23, 42, 0.12); }

/* ── solid: filled, for the one action that should dominate ── */
.acb--solid {
  background-image: linear-gradient(160deg, var(--acb-gem-top), var(--acb));
  border-color: var(--acb);
  color: #FFFFFF;
  box-shadow: 0 2px 6px -2px var(--acb-shadow);
}
.acb--solid:hover { transform: translateY(-1px); box-shadow: 0 9px 18px -9px var(--acb-shadow); }
.acb--solid:active { transform: translateY(1px); box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.22); }
.acb--solid .acb__gem { background-color: rgba(255, 255, 255, 0.22); background-image: none; box-shadow: none; color: #FFFFFF; }

/* ── quiet: the same outline, just no fill until you reach for it ── */
.acb--quiet { background-color: transparent; color: var(--acb-ink); border-color: var(--acb-edge); }
.acb--quiet:hover { background-color: var(--acb-wash); border-color: var(--acb-edge-hi); }
.acb--quiet:active { transform: translateY(1px); }

/* ── the gem ── */
.acb__gem {
  position: relative;
  width: 25px; height: 25px;
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  /* Fully round: the one shape that carries the button's character. */
  border-radius: 50px;
  background-color: var(--acb);
  background-image: linear-gradient(160deg, var(--acb-gem-top), var(--acb-gem-bot));
  color: #FFFFFF;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4), 0 1px 2px var(--acb-shadow);
  transition: transform 0.18s ease, filter 0.18s ease;
}
.acb--dense .acb__gem { width: 21px; height: 21px; }
.acb:hover .acb__gem { transform: scale(1.06); filter: brightness(1.06); }


/* ── busy: the gem spins in place of the icon ── */
.acb--busy { cursor: progress; }
.acb__ring {
  width: 13px; height: 13px;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.42);
  border-top-color: #FFFFFF;
  animation: acbspin 0.7s linear infinite;
}
@keyframes acbspin { to { transform: rotate(360deg); } }

/* ── disabled ── */
.acb:disabled, .acb[aria-disabled='true'] {
  cursor: not-allowed;
  filter: grayscale(0.6);
  opacity: 0.55;
  transform: none !important;
  box-shadow: none;
}

.acb:focus-visible { outline: 2px solid var(--acb); outline-offset: 2px; }

/* On phones the label steps aside — the gem alone carries the action. */
@media (max-width: 599px) {
  .acb__label { display: none; }
  .acb { padding: 4px 5px; gap: 0; }
}
.acb--bare { padding-inline-end: 5px; }

@media (prefers-reduced-motion: reduce) {
  .acb, .acb__gem { transition: none; }
  .acb__ring { animation-duration: 1.6s; }
}

/* Dark mode: the pale wash would vanish, so the body takes a dark surface and
   the ink flips — the gem and the shape stay exactly the same. */
@media (prefers-color-scheme: dark) {
  .acb--light {
    background-color: #1E293B;
    background-image: linear-gradient(180deg, var(--acb-wash), rgba(30, 41, 59, 0));
    color: #E2E8F0;
  }
  .acb--light:hover { background-color: #243349; }
  .acb--quiet { color: #CBD5E1; }
}
</style>
