/**
 * Identity for offline work.
 *
 * Local auto-increment ids stay local: they are how SQLite finds a row, never
 * how the server identifies one. Everything that crosses the wire carries a
 * uuid, and every installation carries its own device id.
 */
import crypto from 'node:crypto';

export function uuid() {
  return crypto.randomUUID();
}

/**
 * SC-POS-KBL-8F31A7 — branch code plus randomness, never sequential.
 *
 * With no branch known the middle group is left out (SC-POS-8F31A7) rather than
 * repeated: the prefix already says POS.
 */
export function suggestDeviceId(branch = null) {
  const letters = String(branch ?? '').replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 3);
  const tail = crypto.randomBytes(3).toString('hex').toUpperCase();
  const branchPart = letters && letters !== 'POS' ? `${letters}-` : '';

  return `SC-POS-${branchPart}${tail}`;
}

/**
 * The number printed on the receipt before the server has seen the sale.
 * Device-scoped, so two tills that both ring sale 1 cannot collide, and the
 * server's own invoice_no stays authoritative for central reporting.
 */
export function deviceInvoiceNo(deviceId, sequence) {
  const short = String(deviceId).split('-').pop();
  return `${short}-${String(sequence).padStart(6, '0')}`;
}

/** Monotonic-ish ISO timestamp with the store's local clock. */
export function nowIso() {
  return new Date().toISOString();
}

export function secondsBetween(a, b) {
  return Math.abs(new Date(a).getTime() - new Date(b).getTime()) / 1000;
}

export function round(value, places = 2) {
  const factor = 10 ** places;
  return Math.round((Number(value) + Number.EPSILON) * factor) / factor;
}
