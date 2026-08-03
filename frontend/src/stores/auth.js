import { defineStore } from 'pinia'
import { api } from '@/boot/axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    permissions: [],
    roles: [],
    isPlatformOwner: false,
    ready: false
  }),

  getters: {
    isAuthenticated: (state) => !!state.user,
    currentCompany: (state) => state.user?.company || null,
    // Platform Owner sits above Super Admin — both bypass the grid.
    can: (state) => (perm) => state.isPlatformOwner || state.permissions.includes(perm) || state.roles.includes('Super Admin'),
    // Main Cost is owner-territory: the Platform Owner or an explicit
    // 'main-cost' grant. Deliberately NOT covered by the Super Admin bypass.
    canMainCost: (state) => state.isPlatformOwner || state.permissions.includes('main-cost'),
    canMainCostEdit: (state) => state.isPlatformOwner || state.permissions.includes('main-cost-edit'),
    // Super Admin (or the Platform Owner above it) — for pages kept out of
    // the role grid entirely, like the Trash vault.
    isSuperAdmin: (state) => state.roles.includes('Super Admin') || !!state.user?.is_super_admin || state.isPlatformOwner
  },

  actions: {
    async login (credentials) {
      const { data } = await api.post('/login', credentials)
      if (data.token) api.setToken(data.token)
      this.setSession(data)
      return data
    },

    /** Counter terminal: tile + PIN instead of email + password. */
    async pinLogin (userId, pin) {
      const { data } = await api.post('/pin/login', { user_id: userId, pin })
      if (data.token) api.setToken(data.token)
      this.setSession(data)
      this.ready = true
      return data
    },

    async fetchUser () {
      try {
        const { data } = await api.get('/user')
        this.setSession(data)
      } catch {
        this.clear()
      } finally {
        this.ready = true
      }
    },

    setSession (data) {
      this.user = data.user
      this.permissions = data.permissions || []
      this.roles = data.roles || []
      this.isPlatformOwner = !!data.is_platform_owner
    },

    async logout () {
      try { await api.post('/logout') } catch { /* ignore */ }
      api.setToken(null)
      this.clear()
    },

    clear () {
      this.user = null
      this.permissions = []
      this.roles = []
      this.isPlatformOwner = false
      api.setToken(null)
    }
  }
})
