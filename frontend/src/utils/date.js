/**
 * Format any date value (ISO string, Date object, YYYY-MM-DD) into a clean display string.
 * Examples:
 *   "2026-06-20T00:00:00.000000Z"  →  "20 Jun 2026"
 *   "2026-06-20 14:35:00"          →  "20 Jun 2026"
 *   null / undefined / ''          →  "—"
 */
export function fmtDate(val) {
  if (!val || val === '—') return '—'
  const d = new Date(val)
  if (isNaN(d.getTime())) return String(val).slice(0, 10) || '—'
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

/**
 * Format date + time.
 * "2026-06-20T14:35:00.000000Z"  →  "20 Jun 2026  14:35"
 */
export function fmtDateTime(val) {
  if (!val || val === '—') return '—'
  const d = new Date(val)
  if (isNaN(d.getTime())) return String(val).slice(0, 16) || '—'
  const date = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
  const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
  return `${date}  ${time}`
}

/** Column names that should be auto-formatted as dates in DataTable */
const DATE_COL_NAMES = new Set([
  'date', 'join_date', 'date_of_birth', 'dob', 'start_date', 'end_date',
  'due_date', 'expiry_date', 'delivery_date', 'issue_date', 'payment_date',
  'updated_at',
])

const DATE_SUFFIX_RE = /_date$|_at$/

/**
 * Returns true if a column's name looks like a date field
 * (but NOT created_at which is used as a row-index counter).
 */
export function isDateColumn(colName) {
  if (colName === 'created_at') return false
  return DATE_COL_NAMES.has(colName) || DATE_SUFFIX_RE.test(colName)
}
