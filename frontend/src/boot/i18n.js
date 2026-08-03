import { defineBoot } from '#q-app'
import { reactive } from 'vue'
import messages from '@/i18n/index.js'

// Lightweight i18n compatible with the legacy app's `$t('Key')` usage.
// Keeps the exact legacy translation files (en/fa/pa) without adding vue-i18n.
const state = reactive({
  locale: localStorage.getItem('locale') || 'en'
})

function translate (key, fallback) {
  if (key == null) return ''
  const dict = messages[state.locale] || messages.en || {}
  if (Object.prototype.hasOwnProperty.call(dict, key)) return dict[key]
  // graceful fallback: humanise the key so labels never show raw camelCase
  return fallback != null
    ? fallback
    : String(key).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]/g, ' ')
}

export const i18n = {
  get locale () { return state.locale },
  set locale (val) {
    state.locale = val
    localStorage.setItem('locale', val)
  },
  t: translate
}

export default defineBoot(({ app }) => {
  app.config.globalProperties.$t = translate
  app.config.globalProperties.$i18n = i18n

  // Apply RTL direction based on stored locale
  const storedLocale = localStorage.getItem('locale') || 'en'
  if (storedLocale === 'fa' || storedLocale === 'pa') {
    document.documentElement.dir = 'rtl'
    document.body.dir = 'rtl'
  }
})
