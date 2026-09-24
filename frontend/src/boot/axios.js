import { defineBoot } from '#q-app'
import axios from 'axios'

// Where the API lives, most specific source first:
//   1. Electron — the main process injects __ELECTRON_API_URL__ at startup.
//   2. public/config.js — an editable file in the upload, so a hosted install
//      can be re-pointed at a different domain without rebuilding.
//   3. VITE_API_URL — baked in at build time.
//   4. localhost, for development.
const API_URL = (
  (typeof window !== 'undefined' && window.__ELECTRON_API_URL__) ||
  (typeof window !== 'undefined' && window.__API_URL__) ||
  import.meta.env.VITE_API_URL ||
  'http://localhost:8000'
).replace(/\/$/, '')

const api = axios.create({
  baseURL: `${API_URL}/api`,
  // Deliberately NOT sending credentials: auth here is a Bearer token (below),
  // never a session cookie. Setting withCredentials would make every request a
  // *credentialed* CORS call, which the browser refuses outright for the
  // desktop app — its page is loaded over file://, so its Origin is the string
  // `null`, and a credentialed request from `null` can never be satisfied.
  withXSRFToken: true,
  headers: { Accept: 'application/json' }
})

// Token-based auth (works under Electron file:// where cookies are unreliable).
// Restore a saved token on startup so the session survives app restarts.
const savedToken = (typeof localStorage !== 'undefined') ? localStorage.getItem('auth_token') : null
if (savedToken) {
  api.defaults.headers.common.Authorization = `Bearer ${savedToken}`
}

// Active branch (multi-branch). Sent on every request so the API scopes data
// to the branch chosen in the header switcher. 'all' = cross-branch view.
const savedBranch = (typeof localStorage !== 'undefined') ? localStorage.getItem('active_branch') : null
if (savedBranch) {
  api.defaults.headers.common['X-Branch-Id'] = savedBranch
}

api.setBranch = (id) => {
  const val = (id === null || id === undefined || id === 'all') ? 'all' : String(id)
  localStorage.setItem('active_branch', val)
  api.defaults.headers.common['X-Branch-Id'] = val
}

api.setToken = (token) => {
  if (token) {
    localStorage.setItem('auth_token', token)
    api.defaults.headers.common.Authorization = `Bearer ${token}`
  } else {
    localStorage.removeItem('auth_token')
    delete api.defaults.headers.common.Authorization
  }
}

// Some shared hosts (LiteSpeed / Apache mod_security) block the DELETE, PUT and
// PATCH verbs outright and answer 403. Tunnel those over POST with a standard
// method-override header — Laravel/Symfony transparently resolves the request
// back to the real verb, so all delete/edit actions keep working everywhere.
api.interceptors.request.use((config) => {
  const method = (config.method || 'get').toLowerCase()
  if (method === 'delete' || method === 'put' || method === 'patch') {
    config.headers = config.headers || {}
    config.headers['X-HTTP-Method-Override'] = method.toUpperCase()
    config.method = 'post'
  }
  return config
})

// No-op CSRF for desktop token auth; kept so existing callers don't break.
api.getCsrf = () => Promise.resolve()

export default defineBoot(({ app }) => {
  app.config.globalProperties.$api = api
})

// Origin of the API host — used to build absolute URLs for public assets
// (e.g. product images served from /storage) that <img> loads without auth.
const apiHost = API_URL
function assetUrl (path) {
  if (!path) return ''
  if (/^https?:\/\//.test(path)) return path
  return `${apiHost}${path.startsWith('/') ? '' : '/'}${path}`
}

export { api, apiHost, assetUrl }
