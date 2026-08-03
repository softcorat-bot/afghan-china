import { defineStore } from 'pinia'

/**
 * Cross-cutting UI state. `posKiosk` puts the register into kiosk mode:
 * the app header and sidebar disappear so the POS frame owns the whole
 * screen (paired with browser fullscreen by the POS toggle).
 */
export const useUiStore = defineStore('ui', {
  state: () => ({
    posKiosk: false
  })
})
