// Registers the PWA service worker so the app is installable on mobile & desktop
// and has a basic offline shell. Registration is deferred to window 'load' so it
// never competes with first paint. Safe no-op where service workers are absent.
import { boot } from 'quasar/wrappers'

export default boot(() => {
  if (!('serviceWorker' in navigator)) return

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((err) => {
      // Non-fatal: the app works fine without the SW, just without offline/install.
      console.warn('SW registration failed:', err)
    })
  })
})
