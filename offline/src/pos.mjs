/**
 * The till's business logic.
 *
 * The rules are the ones the web POS already enforces (same maths, same
 * warnings), because two systems that disagree about what a sale is will
 * eventually disagree about money. What is new here is that every write also
 * puts a row in the outbox, in the same transaction — so "it is saved" and "it
 * will reach the server" are the same statement.
 */
import { enqueue } from './queue.mjs';
import { getMeta, setMeta, transaction } from './db.mjs';
import { deviceInvoiceNo, nowIso, round, uuid } from './ids.mjs';

const PAYMENT_METHODS = ['cash', 'card', 'mobile', 'credit'];

/* ── catalogue ───────────────────────────────────────────────────────────── */

export function searchProducts(db, { query = '', limit = 30 } = {}) {
  const term = `%${String(query).trim()}%`;

  return db.prepare(`select * from products
      where active = 1 and (name like ? or sku like ? or barcode like ? or name_fa like ?)
      order by case when barcode = ? then 0 else 1 end, name
      limit ?`).all(term, term, term, term, String(query).trim(), limit);
}

export function findByBarcode(db, barcode) {
  return db.prepare('select * from products where barcode = ? and active = 1 limit 1').get(String(barcode));
}

/**
 * A product created at the till with no internet. It sells immediately, and the
 * change goes up the same way everything else does; opening stock travels as a
 * movement, never as a number, so the central ledger stays honest.
 */
export function createProduct(db, params = {}) {
  // The screen, the CLI and a device all speak slightly different dialects.
  const pick = (...keys) => keys.map((key) => params[key]).find((value) => value !== undefined && value !== null);

  const deviceId = params.deviceId ?? params.device_id;
  const user = params.user ?? null;
  const name = pick('name');
  const salePrice = Number(pick('salePrice', 'sale_price') ?? 0);
  const costPrice = Number(pick('costPrice', 'cost_price') ?? 0);
  const barcode = pick('barcode') ?? null;
  const sku = pick('sku') ?? null;
  const unit = pick('unit') ?? 'pcs';
  const taxRate = Number(pick('taxRate', 'tax_rate') ?? 0);
  const trackInventory = pick('trackInventory', 'track_inventory') ?? true;
  const openingStock = Number(pick('openingStock', 'opening_stock', 'stock_qty') ?? 0);

  if (!name || !String(name).trim()) throw new Error('A product needs a name.');

  return transaction(db, () => {
    const productUuid = uuid();
    const stock = Number(openingStock) || 0;

    const info = db.prepare(`insert into products
        (uuid, name, sku, barcode, unit, cost_price, sale_price, tax_rate, track_inventory, stock_qty, active, sync_state, updated_at)
        values (?,?,?,?,?,?,?,?,?,?,1,'pending',?)`)
      .run(productUuid, String(name).trim(), sku, barcode, unit, round(Number(costPrice), 2), round(Number(salePrice), 2),
        round(Number(taxRate), 2), trackInventory ? 1 : 0, round(stock, 3), nowIso());

    if (trackInventory && stock > 0) {
      db.prepare(`insert into stock_movements (uuid, product_id, product_uuid, type, qty, reason, note, user_id, ref_type, ref_uuid, created_at, sync_state)
          values (?,?,?, 'increase', ?, 'opening_stock', 'Opening stock captured offline', ?, 'product', ?, ?, 'pending')`)
        .run(uuid(), Number(info.lastInsertRowid), productUuid, stock, user?.id ?? null, productUuid, nowIso());
    }

    enqueue(db, {
      deviceId,
      entityType: 'product',
      entityUuid: productUuid,
      operation: 'create',
      payload: {
        uuid: productUuid,
        name: String(name).trim(),
        sku,
        barcode,
        unit,
        cost_price: round(Number(costPrice), 2),
        sale_price: round(Number(salePrice), 2),
        tax_rate: round(Number(taxRate), 2),
        track_inventory: Boolean(trackInventory),
        stock_qty: trackInventory ? round(stock, 3) : 0,
        captured_at: nowIso(),
        user_uuid: user?.uuid ?? null,
      },
    });

    return db.prepare('select * from products where uuid = ?').get(productUuid);
  });
}

export function findProduct(db, { uuid: productUuid, id = null, barcode = null }) {
  if (productUuid) return db.prepare('select * from products where uuid = ?').get(productUuid);
  if (id) return db.prepare('select * from products where id = ?').get(id);
  if (barcode) return findByBarcode(db, barcode);

  return null;
}

export function stockOnHand(db, productUuid) {
  const row = db.prepare('select stock_qty from products where uuid = ?').get(productUuid);

  return row ? Number(row.stock_qty) : 0;
}

/* ── selling ─────────────────────────────────────────────────────────────── */

/**
 * Ring up a sale. One transaction covers the sale, its lines, its tenders, the
 * stock movements and the outbox row: there is no state in which the shop has
 * sold something the queue does not know about.
 */
export function createSale(db, {
  deviceId,
  user,
  session = null,
  items = [],
  payments = [],
  customerId = null,
  billDiscount = 0,
  note = null,
}) {
  if (!items.length) throw new Error('A sale needs at least one line.');

  return transaction(db, () => {
    const lines = [];
    let subtotal = 0;
    let taxTotal = 0;
    let lineDiscountTotal = 0;

    for (const item of items) {
      const product = findProduct(db, {
        uuid: item.product_uuid ?? item.uuid ?? null,
        id: item.product_id ?? item.id ?? null,
        barcode: item.barcode ?? null,
      });
      if (!product) throw new Error(`Unknown product: ${item.name ?? item.barcode ?? item.product_uuid ?? item.id}`);

      const qty = Number(item.qty ?? 1);
      if (!(qty > 0)) throw new Error(`Quantity for "${product.name}" must be more than zero.`);

      if (product.track_inventory && Number(product.stock_qty) < qty) {
        throw new Error(`Insufficient stock for "${product.name}" (have ${product.stock_qty}, need ${qty}).`);
      }

      const unitPrice = item.unit_price !== undefined ? Number(item.unit_price) : Number(product.sale_price);
      const lineDiscount = Number(item.discount ?? 0);
      const gross = round(unitPrice * qty, 2);
      const net = Math.max(0, gross - lineDiscount);
      const tax = round(net * (Number(product.tax_rate ?? 0) / 100), 2);
      const lineTotal = round(net + tax, 2);

      subtotal += gross;
      lineDiscountTotal += lineDiscount;
      taxTotal += tax;

      lines.push({
        uuid: uuid(),
        product,
        qty,
        unit_price: unitPrice,
        cost_price: Number(item.cost_price ?? product.cost_price ?? 0),
        discount: lineDiscount,
        tax,
        line_total: lineTotal,
      });
    }

    const discount = round(lineDiscountTotal + Number(billDiscount ?? 0), 2);
    const total = round(Math.max(0, subtotal - discount + taxTotal), 2);
    const paid = round(payments.reduce((sum, payment) => sum + Number(payment.amount ?? 0), 0), 2);

    if (paid + 0.009 < total) {
      throw new Error(`Payment ${paid} is less than the total ${total}.`);
    }

    for (const payment of payments) {
      if (!PAYMENT_METHODS.includes(payment.method)) {
        throw new Error(`Unknown payment method: ${payment.method}`);
      }
    }

    const suffix = (getMeta(db, 'invoice_seq', '0') | 0) + 1;
    setMeta(db, 'invoice_seq', String(suffix));

    const saleUuid = uuid();
    const capturedAt = nowIso();
    const invoiceNo = deviceInvoiceNo(deviceId, suffix);

    const saleInfo = db.prepare(`insert into sales
        (uuid, device_invoice_no, session_id, counter_id, customer_id, user_id, sold_at, captured_at,
         subtotal, discount, tax, total, paid, change_due, status, note, sync_state)
        values (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'completed', ?, 'pending')`)
      .run(saleUuid, invoiceNo, session?.id ?? null, session?.counter_id ?? null, customerId, user?.id ?? null,
        capturedAt, capturedAt, round(subtotal, 2), discount, round(taxTotal, 2), total, paid,
        round(Math.max(0, paid - total), 2), note);

    const saleId = Number(saleInfo.lastInsertRowid);

    for (const line of lines) {
      db.prepare(`insert into sale_items
          (uuid, sale_id, product_id, product_uuid, name, barcode, unit_price, cost_price, qty, discount, tax, line_total)
          values (?,?,?,?,?,?,?,?,?,?,?,?)`)
        .run(line.uuid, saleId, line.product.id, line.product.uuid, line.product.name, line.product.barcode,
          line.unit_price, line.cost_price, line.qty, line.discount, line.tax, line.line_total);

      if (!line.product.track_inventory) continue;

      // Stock is a ledger: selling writes a movement, and the product's number is
      // simply the running total of those movements.
      const after = round(Number(line.product.stock_qty) - line.qty, 3);
      db.prepare('update products set stock_qty = ? where id = ?').run(after, line.product.id);

      db.prepare(`insert into stock_movements
          (uuid, product_id, product_uuid, type, qty, reason, note, user_id, ref_type, ref_uuid, created_at, sync_state)
          values (?,?,?, 'decrease', ?, 'sale', ?, ?, 'sale', ?, ?, 'pending')`)
        .run(uuid(), line.product.id, line.product.uuid, line.qty, `Offline sale ${invoiceNo}`, user?.id ?? null, saleUuid, capturedAt);
    }

    const paymentRows = payments.filter((payment) => Number(payment.amount) > 0);

    for (const payment of paymentRows) {
      db.prepare('insert into sale_payments (uuid, sale_id, method, amount, reference) values (?,?,?,?,?)')
        .run(uuid(), saleId, payment.method, round(Number(payment.amount), 2), payment.reference ?? null);
    }

    if (customerId) {
      db.prepare(`update customers set total_spent = total_spent + ?, orders_count = orders_count + 1,
          loyalty_points = loyalty_points + ? where id = ?`)
        .run(total, Math.floor(total / 100), customerId);
    }

    if (session?.id) {
      const column = { cash: 'cash_sales', card: 'card_sales', mobile: 'mobile_sales', credit: 'total_sales' };
      for (const payment of paymentRows) {
        const key = column[payment.method] ?? null;
        if (key === 'total_sales') continue;
        db.prepare(`update cash_sessions set ${key} = ${key} + ? where id = ?`).run(round(Number(payment.amount), 2), session.id);
      }

      db.prepare(`update cash_sessions set total_sales = total_sales + ?, orders_count = orders_count + 1 where id = ?`)
        .run(total, session.id);
    }

    enqueue(db, {
      deviceId,
      entityType: 'sale',
      entityUuid: saleUuid,
      operation: 'create',
      payload: {
        uuid: saleUuid,
        device_invoice_no: invoiceNo,
        captured_at: capturedAt,
        user_uuid: user?.uuid ?? null,
        shift_uuid: session?.uuid ?? null,
        counter_uuid: session?.counter_uuid ?? null,
        customer_uuid: customerId ? db.prepare('select uuid from customers where id = ?').get(customerId)?.uuid ?? null : null,
        subtotal: round(subtotal, 2),
        discount,
        tax: round(taxTotal, 2),
        total,
        paid,
        change_due: round(Math.max(0, paid - total), 2),
        note,
        items: lines.map((line) => ({
          uuid: line.uuid,
          product_uuid: line.product.uuid,
          name: line.product.name,
          barcode: line.product.barcode,
          unit_price: line.unit_price,
          cost_price: line.cost_price,
          qty: line.qty,
          discount: line.discount,
          tax: line.tax,
          line_total: line.line_total,
        })),
        payments: paymentRows.map((payment) => ({
          method: payment.method,
          amount: round(Number(payment.amount), 2),
          reference: payment.reference ?? null,
        })),
      },
    });

    return getSale(db, saleId);
  });
}

export function getSale(db, idOrUuid) {
  const sale = typeof idOrUuid === 'number'
    ? db.prepare('select * from sales where id = ?').get(idOrUuid)
    : db.prepare('select * from sales where uuid = ? or device_invoice_no = ?').get(idOrUuid, idOrUuid);

  if (!sale) return null;

  return {
    ...sale,
    items: db.prepare('select * from sale_items where sale_id = ?').all(sale.id),
    payments: db.prepare('select * from sale_payments where sale_id = ?').all(sale.id),
    customer: sale.customer_id ? db.prepare('select id, uuid, name, phone, loyalty_points from customers where id = ?').get(sale.customer_id) : null,
  };
}

/* ── returns ─────────────────────────────────────────────────────────────── */

/**
 * A return follows the shop's rules: only what is left unrefunded on that bill,
 * restocked as a movement, and recorded as its own append-only record. The
 * server refuses a refund whose sale has not arrived yet — the till simply tries
 * again later, in order.
 */
export function refundSale(db, { deviceId, saleUuid, lines = [], user, reason = 'customer return', amountOverride = null }) {
  return transaction(db, () => {
    const sale = db.prepare('select * from sales where uuid = ? or device_invoice_no = ?').get(saleUuid, saleUuid);
    if (!sale) throw new Error('That receipt is not in this till.');

    const saleItems = db.prepare('select * from sale_items where sale_id = ?').all(sale.id);
    const chosen = [];

    for (const line of lines) {
      const item = saleItems.find((row) => row.id === Number(line.sale_item_id) || row.uuid === line.sale_item_uuid);
      if (!item) throw new Error('That line is not on this receipt.');

      const qty = Number(line.qty ?? 0);
      const remaining = round(Number(item.qty) - Number(item.refunded_qty), 3);

      if (!(qty > 0)) throw new Error('A return needs a quantity above zero.');
      if (qty > remaining + 0.0001) throw new Error(`Only ${remaining} of "${item.name}" can still be returned.`);

      const unitAmount = Number(item.qty) > 0 ? Number(item.line_total) / Number(item.qty) : 0;
      chosen.push({ item, qty, amount: round(unitAmount * qty, 2) });
    }

    if (!chosen.length) throw new Error('Choose at least one line to return.');

    const amount = amountOverride !== null ? round(Number(amountOverride), 2) : round(chosen.reduce((sum, row) => sum + row.amount, 0), 2);
    if (!(amount > 0)) throw new Error('A refund must be more than zero.');

    const refundable = round(Number(sale.total) - Number(sale.refunded_amount ?? 0), 2);
    if (amount > refundable + 0.05) throw new Error(`This refund (${amount}) is more than what is left on ${sale.device_invoice_no} (${refundable}).`);

    const capturedAt = nowIso();
    const refundUuid = uuid();

    const info = db.prepare(`insert into refunds (uuid, sale_id, user_id, amount, reason, captured_at, sync_state)
        values (?,?,?,?,?,?, 'pending')`).run(refundUuid, sale.id, user?.id ?? null, amount, reason, capturedAt);

    const refundId = Number(info.lastInsertRowid);

    for (const row of chosen) {
      db.prepare('insert into refund_items (refund_id, sale_item_id, product_uuid, qty, amount) values (?,?,?,?,?)')
        .run(refundId, row.item.id, row.item.product_uuid, row.qty, row.amount);

      db.prepare('update sale_items set refunded_qty = refunded_qty + ? where id = ?').run(row.qty, row.item.id);

      const product = db.prepare('select * from products where uuid = ?').get(row.item.product_uuid);

      if (product && product.track_inventory) {
        const after = round(Number(product.stock_qty) + row.qty, 3);
        db.prepare('update products set stock_qty = ? where id = ?').run(after, product.id);

        db.prepare(`insert into stock_movements
            (uuid, product_id, product_uuid, type, qty, reason, note, user_id, ref_type, ref_uuid, created_at, sync_state)
            values (?,?,?, 'increase', ?, 'refund', ?, ?, 'refund', ?, ?, 'pending')`)
          .run(uuid(), product.id, product.uuid, row.qty, `Return on ${sale.device_invoice_no}`, user?.id ?? null, refundUuid, capturedAt);
      }
    }

    const refundedTotal = round(Number(sale.refunded_amount ?? 0) + amount, 2);
    db.prepare('update sales set refunded_amount = ?, status = ? where id = ?')
      .run(refundedTotal, refundedTotal >= Number(sale.total) - 0.05 ? 'refunded' : 'partially_refunded', sale.id);

    enqueue(db, {
      deviceId,
      entityType: 'refund',
      entityUuid: refundUuid,
      operation: 'create',
      payload: {
        uuid: refundUuid,
        sale_uuid: sale.uuid,
        captured_at: capturedAt,
        user_uuid: user?.uuid ?? null,
        amount,
        reason,
        items: chosen.map((row) => ({
          sale_item_uuid: row.item.uuid,
          qty: row.qty,
          amount: row.amount,
        })),
      },
    });

    return { uuid: refundUuid, amount, sale: getSale(db, sale.id) };
  });
}

/* ── drawer sessions ─────────────────────────────────────────────────────── */

export function openSession(db, { deviceId, user, counterUuid = null, openingFloat = 0, note = null }) {
  const open = db.prepare("select * from cash_sessions where status = 'open' order by id desc limit 1").get();
  if (open) throw new Error('A drawer session is already open on this till.');

  const counter = counterUuid ? db.prepare('select * from counters where uuid = ?').get(counterUuid) : null;
  const sessionUuid = uuid();
  const openedAt = nowIso();

  const info = db.prepare(`insert into cash_sessions
      (uuid, user_id, counter_id, opened_at, opening_float, status, note, sync_state)
      values (?,?,?,?,?, 'open', ?, 'pending')`)
    .run(sessionUuid, user?.id ?? null, counter?.id ?? null, openedAt, round(Number(openingFloat), 2), note);

  enqueue(db, {
    deviceId,
    entityType: 'cash_session',
    entityUuid: sessionUuid,
    operation: 'create',
    payload: {
      uuid: sessionUuid,
      user_uuid: user?.uuid ?? null,
      opened_at: openedAt,
      opening_float: round(Number(openingFloat), 2),
      status: 'open',
      note,
    },
  });

  return db.prepare('select * from cash_sessions where id = ?').get(Number(info.lastInsertRowid));
}

export function addCashMovement(db, { deviceId, sessionId, user, type, amount, reason = null }) {
  if (!['in', 'out'].includes(type)) throw new Error('Cash movement must be in or out.');

  const value = round(Number(amount), 2);
  if (!(value > 0)) throw new Error('A cash movement must be more than zero.');

  const session = db.prepare('select * from cash_sessions where id = ?').get(sessionId);
  if (!session || session.status !== 'open') throw new Error('Open a drawer session first.');

  return transaction(db, () => {
    const movementUuid = uuid();
    const createdAt = nowIso();

    db.prepare(`insert into cash_movements (uuid, session_id, user_id, type, amount, reason, created_at, sync_state)
        values (?,?,?,?,?,?,?, 'pending')`)
      .run(movementUuid, sessionId, user?.id ?? null, type, value, reason, createdAt);

    db.prepare(`update cash_sessions set ${type === 'in' ? 'cash_in' : 'cash_out'} = ${type === 'in' ? 'cash_in' : 'cash_out'} + ? where id = ?`)
      .run(value, sessionId);

    enqueue(db, {
      deviceId,
      entityType: 'cash_movement',
      entityUuid: movementUuid,
      operation: 'create',
      payload: {
        uuid: movementUuid,
        shift_uuid: session.uuid,
        user_uuid: user?.uuid ?? null,
        type,
        amount: value,
        reason,
        captured_at: createdAt,
      },
    });

    return db.prepare('select * from cash_sessions where id = ?').get(sessionId);
  });
}

/** The Z-report maths, shared by the close screen and the printed report. */
export function sessionTotals(db, sessionId) {
  const session = db.prepare('select * from cash_sessions where id = ?').get(sessionId);
  if (!session) return null;

  const payments = db.prepare(`select p.method, sum(p.amount) as total
      from sale_payments p join sales s on s.id = p.sale_id
      where s.session_id = ? and s.status != 'void'
      group by p.method`).all(sessionId);

  const refunds = db.prepare(`select coalesce(sum(r.amount), 0) as total
      from refunds r join sales s on s.id = r.sale_id where s.session_id = ?`).get(sessionId);

  const byMethod = Object.fromEntries(payments.map((row) => [row.method, round(Number(row.total), 2)]));
  const expected = round(
    Number(session.opening_float) + (byMethod.cash ?? 0) - Number(refunds.total ?? 0)
      + Number(session.cash_in) - Number(session.cash_out),
    2,
  );

  return {
    session,
    by_method: byMethod,
    sales_total: round(Object.values(byMethod).reduce((sum, value) => sum + value, 0), 2),
    refunds: round(Number(refunds.total ?? 0), 2),
    cash_in: round(Number(session.cash_in), 2),
    cash_out: round(Number(session.cash_out), 2),
    expected_cash: expected,
    counted_cash: session.counted_cash === null ? null : round(Number(session.counted_cash), 2),
    variance: session.counted_cash === null ? null : round(Number(session.counted_cash) - expected, 2),
    orders: db.prepare('select count(*) as total from sales where session_id = ?').get(sessionId).total,
  };
}

export function closeSession(db, { deviceId, sessionId, countedCash, note = null, user = null }) {
  const totals = sessionTotals(db, sessionId);
  if (!totals) throw new Error('That drawer session does not exist.');
  if (totals.session.status === 'closed') throw new Error('That drawer session is already closed.');

  const counted = round(Number(countedCash), 2);
  const variance = round(counted - totals.expected_cash, 2);
  const closedAt = nowIso();

  transaction(db, () => {
    db.prepare(`update cash_sessions set closed_at = ?, counted_cash = ?, expected_cash = ?, variance = ?,
        cash_sales = ?, card_sales = ?, mobile_sales = ?, status = 'closed', note = coalesce(?, note), sync_state = 'pending'
        where id = ?`)
      .run(closedAt, counted, totals.expected_cash, variance, totals.by_method.cash ?? 0, totals.by_method.card ?? 0,
        totals.by_method.mobile ?? 0, note, sessionId);

    enqueue(db, {
      deviceId,
      entityType: 'cash_session',
      entityUuid: totals.session.uuid,
      operation: 'update',
      payload: {
        uuid: totals.session.uuid,
        user_uuid: user?.uuid ?? null,
        opened_at: totals.session.opened_at,
        closed_at: closedAt,
        opening_float: round(Number(totals.session.opening_float), 2),
        counted_cash: counted,
        expected_cash: totals.expected_cash,
        variance,
        cash_sales: totals.by_method.cash ?? 0,
        card_sales: totals.by_method.card ?? 0,
        mobile_sales: totals.by_method.mobile ?? 0,
        cash_in: round(Number(totals.session.cash_in), 2),
        cash_out: round(Number(totals.session.cash_out), 2),
        total_sales: totals.sales_total,
        orders_count: Number(totals.orders),
        status: 'closed',
        note,
      },
    });
  });

  return sessionTotals(db, sessionId);
}

export function openSessionRow(db) {
  return db.prepare("select * from cash_sessions where status = 'open' order by id desc limit 1").get() ?? null;
}

/* ── customers ───────────────────────────────────────────────────────────── */

export function createCustomer(db, { deviceId, user, name, phone = null, email = null, address = null }) {
  if (!name || !String(name).trim()) throw new Error('A customer needs a name.');

  return transaction(db, () => {
    const customerUuid = uuid();

    const info = db.prepare(`insert into customers (uuid, name, phone, email, address, sync_state, updated_at)
        values (?,?,?,?,?, 'pending', ?)`)
      .run(customerUuid, String(name).trim(), phone, email, address, nowIso());

    enqueue(db, {
      deviceId,
      entityType: 'customer',
      entityUuid: customerUuid,
      operation: 'create',
      payload: {
        uuid: customerUuid,
        name: String(name).trim(),
        phone,
        email,
        address,
        captured_at: nowIso(),
        user_uuid: user?.uuid ?? null,
      },
    });

    return db.prepare('select * from customers where id = ?').get(Number(info.lastInsertRowid));
  });
}

export function findCustomer(db, { id = null, uuid: customerUuid = null, phone = null }) {
  if (id) return db.prepare('select * from customers where id = ?').get(id);
  if (customerUuid) return db.prepare('select * from customers where uuid = ?').get(customerUuid);
  if (phone) return db.prepare('select * from customers where phone = ? limit 1').get(phone);

  return null;
}

export function searchCustomers(db, { query = '', limit = 20 } = {}) {
  const term = `%${String(query).trim()}%`;

  return db.prepare('select * from customers where name like ? or phone like ? order by name limit ?').all(term, term, limit);
}

/* ── reports (they say what is local and what is central) ────────────────── */

export function salesSummary(db, { from = null, to = null } = {}) {
  const where = [];
  const params = [];

  if (from) { where.push('sold_at >= ?'); params.push(from); }
  if (to) { where.push('sold_at <= ?'); params.push(to); }

  const clause = where.length ? `where ${where.join(' and ')}` : '';

  const rows = db.prepare(`select * from sales ${clause} order by id desc`).all(...params);
  const totals = rows.reduce((acc, sale) => {
    const key = sale.sync_state === 'synced' ? 'synced' : (sale.sync_state === 'conflict' || sale.sync_state === 'failed' ? 'problem' : 'pending');
    acc[key].count++;
    acc[key].total = round(acc[key].total + Number(sale.total), 2);
    acc.count++;
    acc.total = round(acc.total + Number(sale.total), 2);
    acc.refunds = round(acc.refunds + Number(sale.refunded_amount ?? 0), 2);

    return acc;
  }, { count: 0, total: 0, refunds: 0, synced: { count: 0, total: 0 }, pending: { count: 0, total: 0 }, problem: { count: 0, total: 0 } });

  const byMethod = db.prepare(`select p.method, sum(p.amount) as total, count(*) as count
      from sale_payments p join sales s on s.id = p.sale_id ${clause.replace(/sold_at/g, 's.sold_at')}
      group by p.method`).all(...params);

  const topProducts = db.prepare(`select i.name, sum(i.qty) as qty, sum(i.line_total) as total
      from sale_items i join sales s on s.id = i.sale_id ${clause.replace(/sold_at/g, 's.sold_at')}
      group by i.name order by total desc limit 10`).all(...params);

  return {
    from, to,
    totals,
    by_method: byMethod,
    top_products: topProducts,
    queue: db.prepare('select status, count(*) as total from sync_queue group by status').all(),
  };
}
