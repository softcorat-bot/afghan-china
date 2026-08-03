import { ref } from 'vue'
import { api } from '@/boot/axios'

// Shared, cached view of the currency system: the base code and today's
// rates ({ base: 'AFN', rates: { AFN: 1, USD: <AFN per USD> } }). Every money
// form uses rateFor() to prefill the locked rate, and every money display can
// label amounts instead of showing bare numbers.
const base = ref('AFN')
const rates = ref({})
let loaded = false

export function useCurrency () {
  async function loadRates () {
    if (loaded) return
    loaded = true
    try {
      const { data } = await api.get('/exchange-rates/current')
      base.value = data?.base || 'AFN'
      rates.value = data?.rates || {}
    } catch (_) { loaded = false }
  }

  function rateFor (currency) {
    if (!currency || currency === base.value) return 1
    return Number(rates.value[currency] || 0) || 1
  }

  function toBase (amount, currency, rate = null) {
    return Number(amount || 0) * (rate ?? rateFor(currency))
  }

  function fmtAmount (v, currency = '') {
    return Number(v || 0).toLocaleString('en-US') + (currency ? ' ' + currency : '')
  }

  /** "1,200,000 AFN · 50,000 USD" from a { CUR: amount } map. */
  function breakdown (map) {
    return Object.entries(map || {})
      .filter(([, v]) => Math.abs(Number(v)) > 0.009)
      .map(([c, v]) => fmtAmount(v, c))
      .join(' · ')
  }

  /**
   * Smart display for a summary card: single-currency money keeps its own
   * currency front and centre; mixed money shows the base total with the
   * per-currency split underneath.
   */
  function smartMoney (baseTotal, currencyMap, fallbackSub = '') {
    const entries = Object.entries(currencyMap || {}).filter(([, v]) => Math.abs(Number(v)) > 0.009)
    if (entries.length === 1) {
      return { value: Number(entries[0][1]).toLocaleString('en-US'), suffix: entries[0][0], sub: fallbackSub }
    }
    return {
      value: Number(baseTotal || 0).toLocaleString('en-US'),
      suffix: base.value,
      sub: entries.length > 1 ? breakdown(currencyMap) : fallbackSub,
    }
  }

  /**
   * Totals for a ledger of { direction, amount, currency, amount_base, status }
   * rows: confirmed only, per-currency maps plus base-currency sums. This is
   * the Wise/QuickBooks pattern — each currency keeps its own ledger and the
   * consolidated figure lives in the base currency at the locked rates.
   */
  function ledgerTotals (rows) {
    const ok = (rows || []).filter(t => t.status === 'confirmed')
    const maps = { in: {}, out: {}, net: {} }
    let inBase = 0
    let outBase = 0
    ok.forEach(t => {
      const dir = t.direction === 'in' ? 'in' : 'out'
      const amt = Number(t.amount || 0)
      maps[dir][t.currency] = (maps[dir][t.currency] || 0) + amt
      maps.net[t.currency] = (maps.net[t.currency] || 0) + (dir === 'in' ? amt : -amt)
      if (dir === 'in') inBase += Number(t.amount_base || 0)
      else outBase += Number(t.amount_base || 0)
    })
    return { inBase, outBase, netBase: inBase - outBase, maps }
  }

  /**
   * Balance card: a single-currency net keeps its own currency; mixed nets
   * show the base equivalent with a signed per-currency split underneath
   * (e.g. "+2,000 USD · +50,000 AFN").
   */
  function netMoney (netBase, netMap, creditLabel = '', debitLabel = '') {
    const entries = Object.entries(netMap || {}).filter(([, v]) => Math.abs(Number(v)) > 0.009)
    const tag = netBase > 0.009 ? creditLabel : (netBase < -0.009 ? debitLabel : '')
    if (entries.length === 1) {
      return { value: Math.abs(entries[0][1]).toLocaleString('en-US'), suffix: (tag + ' ' + entries[0][0]).trim(), sub: '' }
    }
    return {
      value: Math.abs(netBase || 0).toLocaleString('en-US'),
      suffix: (tag + ' ' + base.value).trim(),
      sub: entries.map(([c, v]) => (v > 0 ? '+' : '−') + Math.abs(v).toLocaleString('en-US') + ' ' + c).join(' · '),
    }
  }

  return { base, rates, loadRates, rateFor, toBase, fmtAmount, breakdown, smartMoney, ledgerTotals, netMoney }
}
