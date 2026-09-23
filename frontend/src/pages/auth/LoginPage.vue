<template>
  <div class="login-box">

    <!-- Header -->
    <div class="login-brand">
      <brand-mark size="36" />
      <div class="col">
        <div class="login-brand__name">Afghan China Shopping Center</div>
        <div class="login-brand__ver">مرکز تجارتی افغان چین · v1.0</div>
      </div>
      <div class="login-lang">
        <span :class="{ 'login-lang--on': $i18n.locale === 'en' }" @click="setLang('en')">EN</span>
        <span class="login-lang__sep">|</span>
        <span :class="{ 'login-lang--on': $i18n.locale === 'fa' }" @click="setLang('fa')">فارسی</span>
        <span class="login-lang__sep">|</span>
        <span :class="{ 'login-lang--on': $i18n.locale === 'pa' }" @click="setLang('pa')">پښتو</span>
        <span class="login-lang__sep">|</span>
        <span :class="{ 'login-lang--on': $i18n.locale === 'zh' }" @click="setLang('zh')">中文</span>
      </div>
    </div>

    <div class="login-title">{{ $t('WelcomeBack') }}</div>
    <div class="login-sub">{{ $t('SignInSub') }}</div>

    <!-- Form -->
    <q-form @submit="onSubmit" class="login-form">

      <div class="login-field-label">{{ $t('EmailAddress') }}</div>
      <q-input v-model="form.email" type="email" outlined dense autofocus
        placeholder="you@afghanchina.af"
        :rules="[v => !!v || 'Required']"
        class="login-input">
        <template #prepend>
          <q-icon name="alternate_email" size="18px" color="grey-5" />
        </template>
      </q-input>

      <div class="login-field-label q-mt-sm">{{ $t('Password') }}</div>
      <q-input v-model="form.password" :type="showPwd ? 'text' : 'password'" outlined dense
        placeholder="••••••••"
        :rules="[v => !!v || 'Required']"
        class="login-input">
        <template #prepend>
          <q-icon name="lock_outline" size="18px" color="grey-5" />
        </template>
        <template #append>
          <q-icon :name="showPwd ? 'visibility_off' : 'visibility'"
            size="18px" color="grey-5" class="cursor-pointer" @click="showPwd = !showPwd" />
        </template>
      </q-input>

      <div class="row items-center justify-between q-mt-xs q-mb-md">
        <q-toggle v-model="form.remember" :label="$t('RememberMe')" dense size="sm" color="cyan-7" />
        <span class="text-caption text-cyan-7 cursor-pointer">{{ $t('ForgotPassword') }}</span>
      </div>

      <q-btn type="submit" unelevated class="full-width login-btn"
        :loading="loading" :disable="loading">
        <span>{{ $t('SignIn') }}</span>
        <q-icon name="arrow_forward" size="18px" class="q-ml-xs" />
      </q-btn>
    </q-form>

    <!-- Counter fast lane -->
    <div class="login-or"><span></span><small>{{ $t('Or') }}</small><span></span></div>
    <router-link to="/pin" class="login-pin">
      <span class="login-pin__ico"><q-icon name="dialpad" size="19px" /></span>
      <span class="login-pin__txt">
        <b>{{ $t('CounterPinLogin') }}</b>
        <small>{{ $t('CounterPinHint') }}</small>
      </span>
      <q-icon name="arrow_forward" size="15px" class="login-pin__go" />
    </router-link>

    <!-- Demo account -->
    <div class="login-demo">
      <div class="login-demo__title">{{ $t('QuickDemoLogin') }}</div>
      <div class="row q-gutter-xs q-mt-xs">
        <q-btn flat dense size="sm" outline color="grey-6" class="login-demo__btn"
          @click="fillDemo">
          {{ $t('Administrator') }}
        </q-btn>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, getCurrentInstance } from 'vue'
import { useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import { apiHost } from '@/boot/axios'
import BrandMark from '@/components/general/BrandMark.vue'

const { proxy } = getCurrentInstance()
const router = useRouter()
const $q = useQuasar()
const auth = useAuthStore()

const showPwd = ref(false)
const loading = ref(false)
const form = reactive({ email: '', password: '', remember: false })

function setLang (l) {
  proxy.$i18n.locale = l
  const rtl = l === 'fa' || l === 'pa'
  document.documentElement.dir = rtl ? 'rtl' : 'ltr'
  document.body.dir = rtl ? 'rtl' : 'ltr'
}

function fillDemo() {
  form.email = 'admin@afghanchina.af'
  form.password = 'password'
}

// Explain *why* sign-in failed. No response at all means the browser never
// reached the API — the backend is down, the API address is wrong, or the
// API refused this page's origin (CORS) — so say that instead of a bare
// "Login failed" that leaves the user guessing.
function loginError (e) {
  const res = e?.response
  if (!res) return proxy.$t('CannotReachServer').replace('{url}', apiHost)
  const errors = res.data?.errors
  const first = errors && Object.values(errors).flat()[0]
  return first || res.data?.message || proxy.$t('LoginFailed')
}

async function onSubmit() {
  loading.value = true
  try {
    await auth.login(form)
    $q.notify({ type: 'positive', position: 'bottom', icon: 'waving_hand', message: 'Welcome back!' })
    router.push({ name: 'dashboard' })
  } catch (e) {
    $q.notify({ type: 'negative', message: loginError(e), timeout: 7000 })
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-box {
  width: 100%;
}

.login-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 36px;
}
.login-brand__name { font-size: 15px; font-weight: 800; color: #0F172A; }
.login-lang { font-size: 12.5px; color: #94A3B8; display: flex; gap: 6px; align-items: center; user-select: none; }
.login-lang span { cursor: pointer; }
.login-lang__sep { cursor: default !important; }
.login-lang--on { color: var(--q-primary); font-weight: 800; }
.login-brand__ver  { font-size: 11px; color: #94A3B8; }

.login-title {
  font-size: 26px;
  font-weight: 800;
  color: #0F172A;
  letter-spacing: -0.5px;
  margin-bottom: 6px;
}
.login-sub {
  font-size: 14px;
  color: #64748B;
  margin-bottom: 28px;
}

.login-form { display: flex; flex-direction: column; }

.login-field-label {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  margin-bottom: 4px;
}
.login-input :deep(.q-field__control) {
  border-radius: 10px !important;
}
.login-input { margin-bottom: 4px; }

.login-btn {
  background: linear-gradient(135deg, #F3D48B, #E9B44C 45%, #C8862D) !important;
  color: #123A66 !important;
  height: 46px;
  border-radius: 12px !important;
  font-size: 15px;
  font-weight: 800;
  letter-spacing: 0.03em;
  box-shadow: 0 10px 24px -10px rgba(200, 134, 45, 0.75) !important;
}
.login-btn:hover { filter: brightness(1.05); }

/* Demo section */
.login-demo {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid #F1F5F9;
}
.login-demo__title {
  font-size: 11px;
  color: #94A3B8;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 600;
  margin-bottom: 6px;
}
.login-demo__btn {
  font-size: 11px;
  border-radius: 6px !important;
}


/* Enterprise entrance: the form settles in with a soft stagger */
.login-box > * { animation: loginIn 0.5s ease both; }
.login-box > *:nth-child(2) { animation-delay: 0.06s; }
.login-box > *:nth-child(3) { animation-delay: 0.12s; }
.login-box > *:nth-child(4) { animation-delay: 0.18s; }
.login-box > *:nth-child(5) { animation-delay: 0.24s; }
@keyframes loginIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) { .login-box > * { animation: none; } }

/* ── Counter fast lane (PIN terminal link) ── */
.login-or { display: flex; align-items: center; gap: 10px; margin: 18px 0 12px; }
.login-or span { flex: 1; height: 1px; background: #E7ECF3; }
.login-or small { font-size: 10px; letter-spacing: 1.6px; text-transform: uppercase; color: #94A3B8; }
.login-pin {
  display: flex; align-items: center; gap: 11px; text-decoration: none;
  background: linear-gradient(120deg, #0E2A47, #17517F);
  border: 1px solid rgba(243, 212, 139, 0.45); border-radius: 13px;
  padding: 11px 14px; transition: all 0.2s ease;
}
.login-pin:hover { transform: translateY(-2px); box-shadow: 0 14px 26px -16px rgba(14, 42, 71, 0.9); border-color: #F3D48B; }
.login-pin__ico {
  width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #F3D48B, #C8862D); color: #123A66;
}
.login-pin__txt { flex: 1; min-width: 0; }
.login-pin__txt b { display: block; font-size: 13px; color: #fff; }
.login-pin__txt small { font-size: 10.5px; color: #9FC1E0; }
.login-pin__go { color: #F3D48B; }
</style>
