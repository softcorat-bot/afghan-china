<template>
  <div class="pt-room">
    <!-- ambient room light + counter reflection -->
    <span class="pt-room__glow"></span>
    <span class="pt-room__floor"></span>

    <!-- ══ THE TERMINAL (hardware) ══════════════════════════════ -->
    <div class="pt-machine" :class="{ 'pt-machine--err': shake, 'pt-machine--ok': granted }">
      <!-- top bezel: brand plate + status lamps -->
      <div class="pt-bezel">
        <img :src="markLogo" alt="" class="pt-bezel__logo">
        <div class="pt-bezel__id">
          <b>AFGHAN CHINA</b>
          <small>POS TERMINAL · ACSC-01</small>
        </div>
        <div class="pt-lamps">
          <span class="pt-lamp pt-lamp--on"><i></i>{{ $t('Power') }}</span>
          <span class="pt-lamp"><i></i>{{ $t('Printer') }}</span>
        </div>
      </div>

      <!-- the screen -->
      <div class="pt-screen">
        <span class="pt-screen__glass"></span>
        <img :src="acLogoDark" alt="" aria-hidden="true" class="pt-screen__mark">

        <!-- STEP 1 — who is at the counter -->
        <transition name="pt-fade" mode="out-in">
          <div v-if="!picked" key="who" class="pt-inner">
            <div class="pt-head">
              <div class="pt-head__t">{{ $t('WhoIsAtTheCounter') }}</div>
              <div class="pt-head__s">{{ $t('TapYourTileToSignIn') }}</div>
            </div>

            <div v-if="loadingStaff" class="pt-center"><q-spinner color="amber-6" size="34px" /></div>
            <div v-else-if="!staff.length" class="pt-empty">
              <q-icon name="badge" size="30px" />
              <div>{{ $t('NoPinStaffYet') }}</div>
              <div class="pt-empty__hint">{{ $t('NoPinStaffHint') }}</div>
            </div>

            <div v-else class="pt-tiles">
              <button v-for="(s, i) in staff" :key="s.id" class="pt-tile"
                :style="`animation-delay:${i * 60}ms`" @click="pick(s)">
                <span class="pt-tile__ring">{{ s.initials }}</span>
                <span class="pt-tile__name">{{ s.name }}</span>
                <span class="pt-tile__go"><q-icon name="arrow_forward" size="13px" /></span>
              </button>
            </div>
          </div>

          <!-- STEP 2 — the PIN pad -->
          <div v-else key="pin" class="pt-inner">
            <div class="pt-who">
              <span class="pt-who__ring">{{ picked.initials }}</span>
              <div class="min-w-0">
                <div class="pt-who__name">{{ picked.name }}</div>
                <div class="pt-who__sub">{{ $t('EnterYourPin') }}</div>
              </div>
              <q-btn dense flat round icon="close" color="blue-grey-5" class="pt-who__x" @click="reset" />
            </div>

            <!-- PIN dots -->
            <div class="pt-dots" :class="{ 'pt-dots--err': shake }">
              <span v-for="n in 6" :key="n" class="pt-dot"
                :class="{ 'pt-dot--on': pin.length >= n, 'pt-dot--next': pin.length === n - 1 }"></span>
            </div>
            <div class="pt-msg" :class="{ 'pt-msg--err': error }">{{ error || (granted ? $t('Welcome') : '&nbsp;') }}</div>

            <!-- keypad -->
            <div class="pt-pad">
              <button v-for="k in keys" :key="k.v" class="pt-key" :class="k.cls" :disable="busy"
                @click="press(k.v)">
                <q-icon v-if="k.icon" :name="k.icon" size="21px" />
                <template v-else>{{ k.v }}</template>
              </button>
            </div>
          </div>
        </transition>
      </div>

      <!-- lower hardware: card slot, receipt slit, speaker, plate -->
      <div class="pt-hw">
        <div class="pt-card-slot">
          <span class="pt-card-slot__line"></span>
          <q-icon name="credit_card" size="15px" />
          <small>{{ $t('InsertCard') }}</small>
        </div>
        <div class="pt-printer">
          <span class="pt-printer__slit"></span>
          <span class="pt-printer__paper"></span>
        </div>
        <div class="pt-speaker"><i v-for="n in 18" :key="n"></i></div>
      </div>

      <div class="pt-foot">
        <q-btn flat no-caps dense color="amber-6" icon="alternate_email" :label="$t('SignInWithEmail')" to="/login" />
        <q-space />
        <span class="pt-foot__ver">v1.0 · AFN ؋</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'
import acLogoDark from '@/assets/brand/ac-logo-dark.svg'
import markLogo from '@/assets/brand/logo-mark.svg'

const router = useRouter()
const auth = useAuthStore()

const staff = ref([])
const loadingStaff = ref(true)
const picked = ref(null)
const pin = ref('')
const busy = ref(false)
const error = ref('')
const shake = ref(false)
const granted = ref(false)

const keys = computed(() => [
  ...['1', '2', '3', '4', '5', '6', '7', '8', '9'].map(v => ({ v })),
  { v: 'del', icon: 'backspace', cls: 'pt-key--soft' },
  { v: '0' },
  { v: 'ok', icon: 'login', cls: 'pt-key--go' },
])

async function loadStaff () {
  try {
    staff.value = (await api.get('/pin/staff')).data
  } catch (_) { staff.value = [] } finally { loadingStaff.value = false }
}

function pick (s) { picked.value = s; pin.value = ''; error.value = '' }
function reset () { picked.value = null; pin.value = ''; error.value = ''; granted.value = false }

function press (v) {
  if (busy.value || granted.value) return
  error.value = ''
  if (v === 'del') { pin.value = pin.value.slice(0, -1); return }
  if (v === 'ok') { submit(); return }
  if (pin.value.length >= 6) return
  pin.value += v
  if (pin.value.length === 4) submit()   // 4-digit PINs sign in on the last tap
}

async function submit () {
  if (pin.value.length < 4 || busy.value) return
  busy.value = true
  try {
    await auth.pinLogin(picked.value.id, pin.value)
    granted.value = true
    setTimeout(() => router.push('/pos'), 550)
  } catch (e) {
    const msg = e?.response?.status === 429
      ? 'Too many attempts — wait a moment'
      : (e?.response?.data?.errors?.pin?.[0] || 'Wrong PIN')
    error.value = msg
    pin.value = ''
    shake.value = true
    setTimeout(() => { shake.value = false }, 550)
  } finally { busy.value = false }
}

// physical keyboard works too (USB numpads are common on POS counters)
function onKey (e) {
  if (!picked.value) return
  if (/^[0-9]$/.test(e.key)) press(e.key)
  else if (e.key === 'Backspace') press('del')
  else if (e.key === 'Enter') press('ok')
  else if (e.key === 'Escape') reset()
}

onMounted(() => { loadStaff(); window.addEventListener('keydown', onKey) })
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<style scoped>
/* ── the room the terminal stands in ── */
.pt-room {
  position: fixed; inset: 0; overflow: hidden;
  background:
    radial-gradient(900px 520px at 50% 8%, #16375C 0%, #0C2340 45%, #071729 100%);
  display: flex; align-items: center; justify-content: center; padding: 18px;
}
.pt-room__glow {
  position: absolute; top: -18%; left: 50%; transform: translateX(-50%);
  width: 720px; height: 520px; border-radius: 50%;
  background: radial-gradient(circle, rgba(243, 212, 139, 0.16), transparent 65%);
  pointer-events: none;
}
.pt-room__floor {
  position: absolute; inset-inline: 0; bottom: 0; height: 22%;
  background: linear-gradient(to top, rgba(243, 212, 139, 0.07), transparent);
  pointer-events: none;
}

/* ── machine chassis ── */
.pt-machine {
  position: relative; width: 100%; max-width: 468px;
  border-radius: 30px; padding: 14px 14px 10px;
  background: linear-gradient(160deg, #2B3648 0%, #1A2332 40%, #121A26 100%);
  border: 1px solid rgba(255, 255, 255, 0.10);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.16),
    inset 0 -2px 6px rgba(0, 0, 0, 0.6),
    0 40px 80px -30px rgba(0, 0, 0, 0.9),
    0 0 0 1px rgba(0, 0, 0, 0.4);
  animation: ptIn 0.55s cubic-bezier(0.2, 0.8, 0.2, 1) both;
}
@keyframes ptIn { from { opacity: 0; transform: translateY(22px) scale(0.97); } }
/* corner screws */
.pt-machine::before, .pt-machine::after {
  content: ''; position: absolute; width: 5px; height: 5px; border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, #6B7787, #2A3340);
  top: 12px;
}
.pt-machine::before { left: 12px; }
.pt-machine::after { right: 12px; }
.pt-machine--err { animation: ptShake 0.5s ease; }
@keyframes ptShake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-9px); }
  40% { transform: translateX(8px); }
  60% { transform: translateX(-5px); }
  80% { transform: translateX(3px); }
}
.pt-machine--ok { box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.7), 0 40px 80px -30px rgba(0, 0, 0, 0.9); }

/* ── top bezel ── */
.pt-bezel { display: flex; align-items: center; gap: 10px; padding: 4px 8px 12px; }
.pt-bezel__logo { width: 30px; opacity: 0.95; filter: drop-shadow(0 2px 6px rgba(243, 212, 139, 0.5)); }
.pt-bezel__id { flex: 1; min-width: 0; line-height: 1.15; }
.pt-bezel__id b { display: block; font-size: 11.5px; font-weight: 900; letter-spacing: 1.6px; color: #F3D48B; }
.pt-bezel__id small { font-size: 9px; letter-spacing: 1.2px; color: rgba(255, 255, 255, 0.42); }
.pt-lamps { display: flex; flex-direction: column; gap: 3px; align-items: flex-end; }
.pt-lamp { display: flex; align-items: center; gap: 4px; font-size: 8px; letter-spacing: 0.8px; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; }
.pt-lamp i { width: 6px; height: 6px; border-radius: 50%; background: #3A4757; }
.pt-lamp--on i { background: #22C55E; box-shadow: 0 0 7px #22C55E; animation: ptBlink 2.6s infinite; }
@keyframes ptBlink { 0%, 88%, 100% { opacity: 1; } 92% { opacity: 0.35; } }

/* ── screen ── */
.pt-screen {
  position: relative; border-radius: 20px; overflow: hidden;
  background: linear-gradient(170deg, #0B1B2E 0%, #0A1524 100%);
  border: 1px solid rgba(0, 0, 0, 0.7);
  box-shadow: inset 0 0 0 1px rgba(243, 212, 139, 0.10), inset 0 8px 26px rgba(0, 0, 0, 0.75);
  min-height: 400px; padding: 18px 18px 20px;
}
.pt-screen__glass {
  position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(146deg, rgba(255, 255, 255, 0.07) 0%, transparent 38%);
}
.pt-screen__mark {
  position: absolute; z-index: 1; width: 300px; top: 50%; left: 50%;
  transform: translate(-50%, -50%); opacity: 0.045; pointer-events: none;
}
.pt-inner { position: relative; z-index: 2; }
.pt-center { display: flex; justify-content: center; padding: 70px 0; }

.pt-head { text-align: center; margin-bottom: 14px; }
.pt-head__t { font-size: 16px; font-weight: 800; color: #fff; }
.pt-head__s { font-size: 11px; color: rgba(255, 255, 255, 0.45); margin-top: 2px; }

/* cashier tiles */
.pt-tiles { display: grid; grid-template-columns: 1fr; gap: 9px; }
.pt-tile {
  display: flex; align-items: center; gap: 12px; text-align: start; font-family: inherit;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.09), rgba(255, 255, 255, 0.03));
  border: 1px solid rgba(243, 212, 139, 0.26); border-radius: 15px;
  padding: 10px 13px; cursor: pointer; color: #fff;
  transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
  animation: ptTileIn 0.4s both;
}
@keyframes ptTileIn { from { opacity: 0; transform: translateY(9px); } }
.pt-tile:hover { transform: translateY(-2px); border-color: #F3D48B; box-shadow: 0 12px 24px -14px rgba(243, 212, 139, 0.6); }
.pt-tile:active { transform: translateY(1px) scale(0.99); }
.pt-tile__ring {
  width: 42px; height: 42px; border-radius: 13px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; font-weight: 900; color: #123A66;
  background: linear-gradient(135deg, #F3D48B, #C8862D);
  box-shadow: 0 6px 14px -8px rgba(243, 212, 139, 0.9);
}
.pt-tile__name { flex: 1; font-size: 14px; font-weight: 700; }
.pt-tile__go { color: #F3D48B; opacity: 0.7; }

.pt-empty { text-align: center; color: rgba(255, 255, 255, 0.5); padding: 54px 10px; font-size: 12.5px; }
.pt-empty .q-icon { color: #F3D48B; margin-bottom: 8px; }
.pt-empty__hint { font-size: 10.5px; color: rgba(255, 255, 255, 0.32); margin-top: 5px; }

/* selected cashier strip */
.pt-who { display: flex; align-items: center; gap: 11px; margin-bottom: 14px; }
.pt-who__ring {
  width: 44px; height: 44px; border-radius: 14px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; font-weight: 900; color: #123A66;
  background: linear-gradient(135deg, #F3D48B, #C8862D);
}
.pt-who__name { font-size: 15px; font-weight: 800; color: #fff; }
.pt-who__sub { font-size: 10.5px; color: rgba(255, 255, 255, 0.45); letter-spacing: 0.6px; text-transform: uppercase; }
.pt-who__x { margin-inline-start: auto; }

/* PIN dots */
.pt-dots { display: flex; justify-content: center; gap: 11px; margin: 4px 0 6px; }
.pt-dot {
  width: 13px; height: 13px; border-radius: 50%;
  background: rgba(255, 255, 255, 0.10); border: 1px solid rgba(243, 212, 139, 0.35);
  transition: all 0.18s ease;
}
.pt-dot--on { background: linear-gradient(135deg, #F3D48B, #C8862D); border-color: #F3D48B; transform: scale(1.12); box-shadow: 0 0 12px rgba(243, 212, 139, 0.6); }
.pt-dot--next { border-color: #F3D48B; box-shadow: 0 0 0 3px rgba(243, 212, 139, 0.14); }
.pt-dots--err .pt-dot { border-color: #EF4444; box-shadow: 0 0 10px rgba(239, 68, 68, 0.5); }
.pt-msg { text-align: center; font-size: 11px; min-height: 16px; color: #86EFAC; letter-spacing: 0.4px; }
.pt-msg--err { color: #FCA5A5; }

/* keypad */
.pt-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 10px; }
.pt-key {
  height: 62px; border-radius: 15px; font-family: inherit;
  font-size: 23px; font-weight: 800; color: #EAF2FA; cursor: pointer;
  background: linear-gradient(180deg, #26313F 0%, #1A2330 100%);
  border: 1px solid rgba(255, 255, 255, 0.09);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.14), 0 4px 10px -4px rgba(0, 0, 0, 0.8);
  transition: transform 0.08s ease, box-shadow 0.12s ease, background 0.12s ease;
}
.pt-key:hover { background: linear-gradient(180deg, #2E3A4A 0%, #1E2836 100%); }
.pt-key:active {
  transform: translateY(2px);
  box-shadow: inset 0 3px 8px rgba(0, 0, 0, 0.7);
  background: #16202C;
}
.pt-key--soft { color: #9FB3C8; font-size: 17px; }
.pt-key--go {
  color: #06281A; background: linear-gradient(180deg, #86EFAC 0%, #22C55E 100%);
  border-color: rgba(255, 255, 255, 0.3);
}
.pt-key--go:hover { background: linear-gradient(180deg, #9BF3BB 0%, #2ED96A 100%); }

/* ── lower hardware ── */
.pt-hw { display: flex; align-items: center; gap: 12px; padding: 12px 8px 4px; }
.pt-card-slot {
  position: relative; flex: 1; display: flex; align-items: center; gap: 6px;
  font-size: 8.5px; letter-spacing: 1px; text-transform: uppercase;
  color: rgba(255, 255, 255, 0.34); padding-top: 8px;
}
.pt-card-slot__line {
  position: absolute; top: 0; inset-inline: 0; height: 4px; border-radius: 3px;
  background: linear-gradient(180deg, #05090E, #1B2430);
  box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.95), 0 1px 0 rgba(255, 255, 255, 0.06);
}
.pt-card-slot .q-icon { color: rgba(243, 212, 139, 0.6); }
.pt-printer { position: relative; width: 96px; height: 18px; }
.pt-printer__slit {
  position: absolute; inset-inline: 0; top: 4px; height: 5px; border-radius: 3px;
  background: linear-gradient(180deg, #04080C, #18202B);
  box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.95);
}
.pt-printer__paper {
  position: absolute; inset-inline: 8px; top: 8px; height: 9px; border-radius: 0 0 3px 3px;
  background: linear-gradient(180deg, #E8EEF5, #B9C6D4);
  opacity: 0.85;
}
.pt-speaker { display: flex; gap: 2px; }
.pt-speaker i { width: 2px; height: 12px; border-radius: 2px; background: rgba(255, 255, 255, 0.07); }

.pt-foot { display: flex; align-items: center; padding: 2px 4px 0; }
.pt-foot__ver { font-size: 9px; letter-spacing: 1px; color: rgba(255, 255, 255, 0.28); }

/* transitions */
.pt-fade-enter-active, .pt-fade-leave-active { transition: opacity 0.18s ease, transform 0.18s ease; }
.pt-fade-enter-from { opacity: 0; transform: translateX(14px); }
.pt-fade-leave-to { opacity: 0; transform: translateX(-14px); }

.min-w-0 { min-width: 0; }
@media (max-height: 720px) {
  .pt-screen { min-height: 344px; }
  .pt-key { height: 48px; }
}
@media (prefers-reduced-motion: reduce) {
  .pt-machine, .pt-tile, .pt-lamp--on i { animation: none; }
}
</style>
