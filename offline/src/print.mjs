/**
 * Receipt printing for the offline till.
 *
 * Two delivery paths, one receipt model:
 *
 *   dialog — the browser window prints (window.print()). Full control of the
 *            layout, works with any printer the OS knows, needs one click.
 *
 *   raw    — ESC/POS bytes pushed straight to a shared Windows printer
 *            (cmd copy /b → \\COMPUTERNAME\<share>). Silent, no dialog: the
 *            classic thermal-printer path. Windows only; ASCII text only —
 *            anything non-ASCII degrades to '?', because ESC/POS code pages
 *            cannot represent Dari/Pashto script. Shops that print Dari
 *            receipts should keep the dialog path, which renders everything.
 *
 * A sale is already committed before printing is ever attempted: a printer
 * that is off, out of paper or misconfigured shows up as a print error,
 * never as a lost sale.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFile } from 'node:child_process';

import { getSale } from './pos.mjs';
import { getMeta } from './db.mjs';
import { logs } from './log.mjs';

const ESC = 0x1b;
const GS = 0x1d;

export const PRINTER_MODES = ['dialog', 'raw'];

/** The printer-related settings, with the same defaults the settings screen shows. */
export function printerSettings(db) {
  return {
    printer_mode: getMeta(db, 'printer_mode', 'dialog'),
    printer_share: getMeta(db, 'printer_share', ''),
    printer_width: Math.min(64, Math.max(24, Number(getMeta(db, 'printer_width', '42')))),
    printer_drawer_kick: getMeta(db, 'printer_drawer_kick', '0') === '1',
    receipt_header: getMeta(db, 'receipt_header', 'SoftCora POS'),
    receipt_footer: getMeta(db, 'receipt_footer', 'Thank you for shopping with us'),
    currency: getMeta(db, 'currency', 'AFN'),
  };
}

/**
 * Everything a receipt needs to know about a sale, gathered once so the text
 * renderer, the ESC/POS renderer and the browser renderer all tell the same story.
 */
export function buildReceiptModel(db, saleUuidOrInvoice) {
  const sale = getSale(db, saleUuidOrInvoice);
  const settings = printerSettings(db);

  if (!sale) {
    const error = new Error('That receipt is not in this till.');
    error.status = 404;
    throw error;
  }

  const cashier = sale.user_id
    ? db.prepare('select name from users where id = ?').get(sale.user_id)?.name ?? null
    : null;

  return {
    header: settings.receipt_header,
    footer: settings.receipt_footer,
    currency: settings.currency,
    width: settings.width,
    invoice_no: sale.device_invoice_no,
    server_invoice_no: sale.server_invoice_no,
    sold_at: sale.sold_at,
    cashier,
    customer: sale.customer?.name ?? null,
    device_id: getMeta(db, 'device_id'),
    items: (sale.items ?? []).map((item) => ({
      name: item.name,
      qty: Number(item.qty),
      unit_price: Number(item.unit_price),
      discount: Number(item.discount ?? 0),
      line_total: Number(item.line_total),
    })),
    subtotal: Number(sale.subtotal),
    discount: Number(sale.discount ?? 0),
    tax: Number(sale.tax ?? 0),
    total: Number(sale.total),
    paid: Number(sale.paid),
    change_due: Number(sale.change_due ?? 0),
    refunded_amount: Number(sale.refunded_amount ?? 0),
    payments: (sale.payments ?? []).map((payment) => ({ method: payment.method, amount: Number(payment.amount) })),
  };
}

/* ── plain-text layout ──────────────────────────────────────────────────── */
/* The same rows drive the text receipt and the ESC/POS one, so the two never  */
/* disagree about the maths.                                                   */

function money(amount, currency) {
  const rendered = Number(amount ?? 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  return currency ? `${rendered} ${currency}` : rendered;
}

function row(left, right, width) {
  const space = width - String(left).length - String(right).length;
  return space >= 1
    ? `${left}${' '.repeat(space)}${right}`
    : `${`${left}`.slice(0, Math.max(1, width - String(right).length - 1))} ${right}`.slice(0, width);
}

function rule(width, character = '-') {
  return character.repeat(width);
}

/** Render the receipt to plain text lines (also the browser dialog's <pre>). */
export function renderText(model) {
  const width = model.width ?? 42;
  const lines = [];

  lines.push(center(model.header ?? 'SoftCora POS', width, true));
  if (model.device_id) lines.push(center(model.device_id, width));
  lines.push(rule(width));
  lines.push(row(model.invoice_no, formatSoldAt(model.sold_at), width));
  if (model.cashier) lines.push(row('Cashier', model.cashier, width));
  if (model.customer) lines.push(row('Customer', model.customer, width));
  lines.push(rule(width));

  for (const item of model.items) {
    lines.push(String(item.name).slice(0, width));
    lines.push(row(`   ${item.qty} x ${money(item.unit_price, '')}`, money(item.line_total, ''), width));
  }

  lines.push(rule(width));
  lines.push(row('Subtotal', money(model.subtotal, model.currency), width));
  if (model.discount > 0) lines.push(row('Discount', `-${money(model.discount, model.currency)}`, width));
  if (model.tax > 0) lines.push(row('Tax', money(model.tax, model.currency), width));
  lines.push(row('TOTAL', money(model.total, model.currency), width).toUpperCase());
  lines.push(rule(width, '='));

  if (model.payments.length === 0) {
    lines.push(row('Paid', money(model.paid, model.currency), width));
  } else {
    for (const payment of model.payments) {
      lines.push(row(`Paid (${payment.method})`, money(payment.amount, model.currency), width));
    }
  }

  if (model.change_due > 0) lines.push(row('Change', money(model.change_due, model.currency), width));
  if (model.refunded_amount > 0) lines.push(row('Refunded so far', money(model.refunded_amount, model.currency), width));

  lines.push(rule(width));
  if (model.footer) lines.push(center(model.footer, width));
  lines.push('');

  return lines.join('\n');
}

function center(text, width, wide = false) {
  const value = String(text ?? '');
  if (value.length >= width) return value.slice(0, width);
  const left = Math.floor((width - value.length) / 2);
  return wide ? `${' '.repeat(Math.max(0, left - 1))}${value.toUpperCase()}` : `${' '.repeat(left)}${value}`;
}

function formatSoldAt(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return String(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/* ── ESC/POS ────────────────────────────────────────────────────────────── */

/** Turn UTF-8 text into byte-safe ASCII for a code-page starved printer. */
function ascii(text) {
  return Buffer.from(String(text).replace(/[^\x20-\x7e]/g, '?'), 'ascii');
}

/**
 * The bytes a thermal printer understands. Commands used: ESC @ (init),
 * ESC a (align), ESC E (bold), GS ! (size), LF, ESC p (drawer), GS V (cut).
 */
export function renderEscPos(model, { drawerKick = false } = {}) {
  const width = model.width ?? 42;
  const chunks = [];

  const push = (bytes) => chunks.push(Buffer.from(bytes));
  const text = (value) => chunks.push(ascii(`${value}\n`));
  const line = (left, right = '') => text(row(left, right, width));

  push([ESC, 0x40]);                        // init
  push([ESC, 0x61, 0x01]);                  // center
  push([GS, 0x21, 0x11]);                   // double width + height
  text(`${model.header ?? 'SoftCora POS'}`);
  push([GS, 0x21, 0x00]);                   // normal size
  if (model.device_id) text(model.device_id);
  push([ESC, 0x61, 0x00]);                  // left
  text(rule(width, '-'));
  line(model.invoice_no, formatSoldAt(model.sold_at));
  if (model.cashier) line('Cashier', model.cashier);
  if (model.customer) line('Customer', model.customer);
  text(rule(width, '-'));

  for (const item of model.items) {
    text(item.name);
    line(`   ${item.qty} x ${money(item.unit_price, '')}`, money(item.line_total, ''));
  }

  text(rule(width, '-'));
  line('Subtotal', money(model.subtotal, model.currency));
  if (model.discount > 0) line('Discount', `-${money(model.discount, model.currency)}`);
  if (model.tax > 0) line('Tax', money(model.tax, model.currency));

  push([ESC, 0x45, 0x01]);                  // bold on
  line('TOTAL', money(model.total, model.currency));
  push([ESC, 0x45, 0x00]);                  // bold off
  text(rule(width, '='));

  for (const payment of model.payments.length ? model.payments : [{ method: 'paid', amount: model.paid }]) {
    line(`Paid (${payment.method})`, money(payment.amount, model.currency));
  }
  if (model.change_due > 0) line('Change', money(model.change_due, model.currency));
  if (model.refunded_amount > 0) line('Refunded so far', money(model.refunded_amount, model.currency));

  text(rule(width, '-'));
  if (model.footer) {
    push([ESC, 0x61, 0x01]);
    text(model.footer);
    push([ESC, 0x61, 0x00]);
  }

  text('');
  text('');
  if (drawerKick) push([ESC, 0x70, 0x00, 0x19, 0xfa]);   // kick the drawer
  push([GS, 0x56, 0x41, 0x03]);                          // feed & cut

  return Buffer.concat(chunks);
}

/* ── delivery ───────────────────────────────────────────────────────────── */

/**
 * Send raw bytes to a shared Windows printer. The printer must be shared on
 * the till PC (printer properties → Share) and `printer_share` holds that
 * share name. Anything else — Linux/macOS host, missing share, spooler down —
 * answers with an explicit error the till screen can show; the sale itself is
 * untouched either way.
 */
/** The checks every raw print must pass, whatever the receipt is. */
function assertRawConfigured(db) {
  const settings = printerSettings(db);

  if (settings.printer_mode !== 'raw') {
    const error = new Error('The printer is set to browser-dialog mode; print from the window shown instead.');
    error.status = 409;
    throw error;
  }

  if (!settings.printer_share) {
    const error = new Error('No printer share name is configured (Settings → Printer).');
    error.status = 409;
    throw error;
  }

  if (process.platform !== 'win32') {
    const error = new Error('Silent thermal printing needs Windows; keep the browser-dialog printer mode here.');
    error.status = 501;
    throw error;
  }

  return settings;
}

/** Send an already-built receipt model to the shared Windows printer. */
export async function printModelRaw(db, model) {
  const settings = assertRawConfigured(db);

  const bytes = renderEscPos(model, { drawerKick: settings.printer_drawer_kick });
  const target = settings.printer_share.includes('\\')
    ? settings.printer_share
    : `\\\\${process.env.COMPUTERNAME ?? 'localhost'}\\${settings.printer_share}`;

  const file = path.join(os.tmpdir(), `softcora-receipt-${Date.now()}.bin`);

  try {
    fs.writeFileSync(file, bytes);

    await new Promise((resolve, reject) => {
      execFile('cmd.exe', ['/c', 'copy', '/b', file, target], { timeout: 15000 }, (error, _stdout, stderr) => {
        if (error) reject(new Error((stderr || error.message || 'the printer did not accept the receipt').trim()));
        else resolve();
      });
    });

    logs.app('receipt printed', { invoice: model.invoice_no, target });
    return { ok: true, target, bytes: bytes.length, invoice_no: model.invoice_no };
  } catch (error) {
    logs.error('receipt printing failed', { invoice: model.invoice_no, target, error: error.message });
    const wrapped = new Error(`The printer did not take the receipt: ${error.message}`);
    wrapped.status = 502;
    throw wrapped;
  } finally {
    try { fs.rmSync(file, { force: true }); } catch { /* temp dir cleans up */ }
  }
}

/**
 * Print a committed sale on the shared Windows printer.
 * Entry point used by the print API; validates configuration first.
 */
export async function printRaw(db, saleUuidOrInvoice) {
  const model = buildReceiptModel(db, saleUuidOrInvoice);
  return printModelRaw(db, model);
}

/** A test receipt for the settings screen — no sale required, everything labelled. */
export function testReceiptModel(db) {
  const settings = printerSettings(db);

  return {
    header: settings.receipt_header,
    footer: settings.receipt_footer,
    currency: settings.currency,
    width: settings.printer_width,
    invoice_no: `${getMeta(db, 'device_id') ?? 'POS'}-TEST`,
    sold_at: new Date().toISOString(),
    cashier: 'Printer test',
    customer: null,
    device_id: getMeta(db, 'device_id'),
    items: [
      { name: 'Test item A', qty: 2, unit_price: 100, discount: 0, line_total: 200 },
      { name: 'Test item B', qty: 1, unit_price: 50, discount: 0, line_total: 50 },
    ],
    subtotal: 250,
    discount: 0,
    tax: 0,
    total: 250,
    paid: 250,
    change_due: 0,
    refunded_amount: 0,
    payments: [{ method: 'cash', amount: 250 }],
  };
}
