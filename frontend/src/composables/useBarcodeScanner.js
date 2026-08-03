/**
 * Global barcode scanner — the keyboard-wedge engine behind every module.
 *
 * A hardware scanner is a keyboard that types impossibly fast and usually
 * presses Enter at the end. That is the whole trick: we watch keystrokes at the
 * document level and judge them by *rhythm*, not by which field has focus. A
 * burst of characters 10ms apart is a scan; the same characters typed by a
 * person 120ms apart is someone using the keyboard, and we leave it alone.
 *
 * Because the listener lives on the document, the user never has to click a
 * search box first — the scanner behaves like hardware, not like a form field.
 *
 * Usage:
 *   useBarcodeScanner({ onScan: (code) => …, mode: 'search' })
 *
 * The composable only *detects*. What a scan means is the page's business —
 * that is what keeps this reusable across POS, Purchases, Transfers and
 * anything added later.
 */
import { onMounted, onUnmounted, ref, unref } from 'vue'

/** Gap (ms) below which consecutive keystrokes look mechanical rather than human. */
const FAST_GAP_MS = 40
/** A pause longer than this starts a fresh code. */
const RESET_GAP_MS = 300
/** Scanners that send no Enter: fire this long after the last character. */
const IDLE_FLUSH_MS = 120
/** Shorter than this and it is probably not a barcode. */
const MIN_CODE_LENGTH = 4

/** True when the keystroke belongs to something the user is typing into. */
function isTypingTarget (el) {
  if (!el) return false
  if (el.isContentEditable) return true
  const tag = el.tagName
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true
  if (tag !== 'INPUT') return false
  // Checkboxes and buttons don't swallow text, so a scan over them is still a scan.
  return !['checkbox', 'radio', 'button', 'submit', 'range', 'file'].includes(el.type)
}

export function useBarcodeScanner (options = {}) {
  const {
    onScan,
    enabled = true,
    minLength = MIN_CODE_LENGTH,
    // Set true to also capture while the user is inside a text field. Off by
    // default so typing a filter never fires phantom scans.
    captureInInputs = false,
  } = options

  const buffer = ref('')
  const lastCode = ref('')
  const listening = ref(false)

  let lastKeyAt = 0
  let fastKeys = 0
  let idleTimer = null

  function reset () {
    buffer.value = ''
    fastKeys = 0
    clearTimeout(idleTimer)
  }

  /** A burst counts as a scan when it is long enough AND was typed too fast to be human. */
  function qualifies () {
    return buffer.value.length >= minLength && fastKeys >= Math.min(3, minLength - 1)
  }

  function flush () {
    const code = buffer.value.trim()
    reset()
    if (code.length < minLength) return
    lastCode.value = code
    if (typeof onScan === 'function') onScan(code)
  }

  function onKeyDown (e) {
    if (!unref(enabled)) return
    if (e.ctrlKey || e.altKey || e.metaKey) return
    if (!captureInInputs && isTypingTarget(e.target)) return

    const now = performance.now()
    const gap = now - lastKeyAt
    lastKeyAt = now

    if (e.key === 'Enter') {
      // The scanner's terminator. Only claim it when the burst looked mechanical,
      // so a person pressing Enter on a form is never hijacked.
      if (qualifies()) {
        e.preventDefault()
        e.stopPropagation()
        flush()
      } else {
        reset()
      }
      return
    }

    // Only printable single characters form a code.
    if (e.key.length !== 1) return

    if (gap > RESET_GAP_MS) {
      buffer.value = ''
      fastKeys = 0
    } else if (gap <= FAST_GAP_MS) {
      fastKeys += 1
    }

    buffer.value += e.key

    // Scanners configured without a suffix: flush once the burst goes quiet.
    clearTimeout(idleTimer)
    idleTimer = setTimeout(() => { if (qualifies()) flush(); else reset() }, IDLE_FLUSH_MS)
  }

  onMounted(() => {
    // Capture phase: we get the keystroke before any component-level handler,
    // which is what lets a scan work no matter what is on screen.
    document.addEventListener('keydown', onKeyDown, true)
    listening.value = true
  })

  onUnmounted(() => {
    document.removeEventListener('keydown', onKeyDown, true)
    clearTimeout(idleTimer)
    listening.value = false
  })

  return { buffer, lastCode, listening, reset }
}

/**
 * The confirmation beep. Synthesised rather than shipped as an audio file so
 * it works offline, adds nothing to the bundle, and never 404s.
 */
let audioCtx = null
export function scannerBeep (kind = 'ok') {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext
    if (!Ctx) return
    audioCtx = audioCtx || new Ctx()
    if (audioCtx.state === 'suspended') audioCtx.resume()

    const tones = {
      ok: [[1180, 0.07]],
      many: [[880, 0.06], [1180, 0.08]],
      error: [[320, 0.16]],
    }[kind] || [[1180, 0.07]]

    let at = audioCtx.currentTime
    for (const [freq, dur] of tones) {
      const osc = audioCtx.createOscillator()
      const gain = audioCtx.createGain()
      osc.type = 'square'
      osc.frequency.value = freq
      // A short envelope — a click, not a note.
      gain.gain.setValueAtTime(0.0001, at)
      gain.gain.exponentialRampToValueAtTime(0.16, at + 0.01)
      gain.gain.exponentialRampToValueAtTime(0.0001, at + dur)
      osc.connect(gain).connect(audioCtx.destination)
      osc.start(at)
      osc.stop(at + dur + 0.02)
      at += dur + 0.03
    }
  } catch (_) { /* a silent beep must never break a sale */ }
}
