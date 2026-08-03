/* Aria Herat ERP — lightweight PWA service worker.
   Makes the app installable and gives it a basic offline shell. It never
   caches API traffic (always live), uses network-first for page navigations
   (so updates flow), and stale-while-revalidate for same-origin static assets. */

const CACHE = 'aria-erp-v1'
const SHELL = ['/', '/index.html', '/manifest.json', '/icons/icon.svg']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return

  const url = new URL(req.url)
  // Never intercept API or cross-origin (map tiles, etc.) — always go to network.
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api')) return

  // Page navigations: network-first, fall back to cached shell when offline.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => { cachePut(req, res.clone()); return res }).catch(() => caches.match(req).then((r) => r || caches.match('/index.html')))
    )
    return
  }

  // Static assets: stale-while-revalidate.
  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req).then((res) => { cachePut(req, res.clone()); return res }).catch(() => cached)
      return cached || network
    })
  )
})

function cachePut (req, res) {
  if (res && res.status === 200 && res.type === 'basic') {
    caches.open(CACHE).then((c) => c.put(req, res))
  }
}
