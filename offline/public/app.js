/* SoftCora offline till — talks only to the local service. */
const state = {
  token: localStorage.getItem('softcora.session') || null,
  user: null,
  cart: [],
  customer: null,
  payments: [],
  lastSale: null,
  status: null,
};

const $ = (id) => document.getElementById(id);
const money = (value) => Number(value ?? 0).toFixed(2);

async function api(method, path, body = null) {
  const response = await fetch(path, {
    method,
    headers: {
      accept: 'application/json',
      ...(body ? { 'content-type': 'application/json' } : {}),
      ...(state.token ? { authorization: `Bearer ${state.token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const payload = await response.json().catch(() => ({}));
  if (response.status === 401 && path !== '/api/auth/login') showLogin();

  return { ok: response.ok, status: response.status, payload };
}

/* ── navigation ─────────────────────────────────────────────────────────── */

document.querySelectorAll('nav.tabs button').forEach((button) => {
  button.addEventListener('click', () => showView(button.dataset.view));
});

function showView(name) {
  document.querySelectorAll('nav.tabs button').forEach((b) => b.classList.toggle('active', b.dataset.view === name));
  document.querySelectorAll('.view').forEach((view) => view.classList.toggle('active', view.id === `view-${name}`));
  if (name === 'sync') refreshSync();
  if (name === 'conflicts') refreshConflicts();
  if (name === 'settings') refreshSettings();
  if (name === 'drawer') refreshDrawer();
}

/* ── login ──────────────────────────────────────────────────────────────── */

function showLogin() {
  $('loginOverlay').classList.remove('hidden');
}

$('loginForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('loginMessage').textContent = 'Checking…';

  const { ok, payload } = await api('POST', '/api/auth/login', {
    identifier: $('loginUser').value,
    secret: $('loginSecret').value,
    use_pin: $('loginPin').checked,
  });

  if (!ok || !payload.token) {
    $('loginMessage').textContent = payload.reason === 'unknown_user'
      ? 'This user is not known on this till yet. Sign in once while online, or ask an administrator to add them.'
      : 'Wrong password or PIN.';

    return;
  }

  state.token = payload.token;
  state.user = payload.user;
  localStorage.setItem('softcora.session', payload.token);
  $('loginOverlay').classList.add('hidden');
  $('loginMessage').textContent = '';
  boot();
});

/* ── the till screen ────────────────────────────────────────────────────── */

async function boot() {
  const me = await api('GET', '/api/auth/me');
  if (!me.ok) return showLogin();

  state.user = me.payload.user;
  if (!state.token) {
    state.token = localStorage.getItem('softcora.session');
  }

  $('loginOverlay').classList.add('hidden');
  await Promise.all([loadProducts(''), loadDevice(), refreshSync(), refreshDrawer()]);
}

$('search').addEventListener('input', (event) => loadProducts(event.target.value));

$('barcode').addEventListener('keydown', async (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();

  const code = event.target.value.trim();
  event.target.value = '';
  if (!code) return;

  const { payload } = await api('GET', `/api/products/barcode/${encodeURIComponent(code)}`);
  if (payload.uuid) addToCart(payload);
  else message(`No product with barcode ${code}`);

  loadProducts($('search').value);
});

async function loadProducts(query) {
  const { payload } = await api('GET', `/api/products?q=${encodeURIComponent(query)}`);
  const grid = $('productGrid');
  grid.innerHTML = '';

  for (const product of payload.data ?? []) {
    const card = document.createElement('button');
    card.className = 'product';
    card.innerHTML = `<strong>${escapeHtml(product.name)}</strong>
      <small>${escapeHtml(product.sku ?? '')} ${product.track_inventory ? `· ${product.stock_qty}` : ''}</small>
      <b>${money(product.sale_price)}</b>`;
    card.addEventListener('click', () => addToCart(product));
    grid.appendChild(card);
  }
}

function addToCart(product, qty = 1) {
  const existing = state.cart.find((line) => line.product_uuid === product.uuid);

  if (existing) existing.qty += qty;
  else state.cart.push({
    product_uuid: product.uuid,
    id: product.id,
    name: product.name,
    barcode: product.barcode,
    unit_price: Number(product.sale_price),
    tax_rate: Number(product.tax_rate),
    track_inventory: Number(product.track_inventory),
    stock_qty: Number(product.stock_qty),
    qty,
    discount: 0,
  });

  renderCart();
}

function renderCart() {
  const lines = $('cartLines');
  lines.innerHTML = '';

  if (!state.cart.length) {
    lines.innerHTML = '<p class="muted">Cart is empty.</p>';
    return renderTotals();
  }

  state.cart.forEach((line, index) => {
    const row = document.createElement('div');
    row.className = 'cart-line';
    row.innerHTML = `
      <div class="grow"><strong>${escapeHtml(line.name)}</strong>
        <small>${money(line.unit_price)} × <input type="number" min="0.001" step="1" value="${line.qty}" data-qty="${index}" /></small>
      </div>
      <b>${money(lineTotal(line))}</b>
      <button class="btn ghost" data-remove="${index}">✕</button>`;
    lines.appendChild(row);
  });

  lines.querySelectorAll('[data-qty]').forEach((input) => {
    input.addEventListener('change', (event) => {
      state.cart[Number(event.target.dataset.qty)].qty = Number(event.target.value);
      renderCart();
    });
  });

  lines.querySelectorAll('[data-remove]').forEach((button) => {
    button.addEventListener('click', () => {
      state.cart.splice(Number(button.dataset.remove), 1);
      renderCart();
    });
  });

  renderTotals();
}

function lineTotal(line) {
  const gross = line.unit_price * line.qty;
  const net = Math.max(0, gross - (line.discount ?? 0));
  const tax = net * (line.tax_rate / 100);
  return Math.round((net + tax) * 100) / 100;
}

function renderTotals() {
  const subtotal = state.cart.reduce((sum, line) => sum + line.unit_price * line.qty, 0);
  const tax = state.cart.reduce((sum, line) => sum + Math.max(0, line.unit_price * line.qty - line.discount) * (line.tax_rate / 100), 0);
  const discount = Number($('billDiscount').value || 0);
  const total = Math.max(0, subtotal - discount + tax);

  $('tSubtotal').textContent = money(subtotal);
  $('tTax').textContent = money(tax);
  $('tTotal').textContent = money(total);
}

$('billDiscount').addEventListener('input', renderTotals);

$('addCustomer').addEventListener('click', async () => {
  const name = prompt('Customer name');
  if (!name) return;

  const phone = prompt('Phone (optional)') ?? null;
  const { payload } = await api('POST', '/api/customers', { name, phone });

  if (payload.customer) {
    state.customer = payload.customer;
    $('customerChip').classList.remove('hidden');
    $('customerChip').textContent = `Customer: ${payload.customer.name}`;
  }
});

$('newProduct')?.addEventListener('click', async () => {
  const name = prompt('Product name');
  if (!name) return;

  const salePrice = Number(prompt('Selling price', '0') ?? 0);
  const barcode = prompt('Barcode (optional, scan it now)') || null;
  const openingStock = Number(prompt('Opening stock (pieces)', '0') ?? 0);

  const { ok, payload } = await api('POST', '/api/products', {
    name, sale_price: salePrice, barcode, opening_stock: openingStock,
  });

  if (!ok) return message(payload.message ?? 'The product could not be saved.');

  message(`${payload.product.name} saved and queued for the server.`);
  if (payload.product.barcode && payload.product.barcode === barcode) addToCart(payload.product);
  loadProducts($('search').value);
});

$('customerSearch').addEventListener('change', async (event) => {
  const { payload } = await api('GET', `/api/customers?q=${encodeURIComponent(event.target.value)}`);
  const first = payload.data?.[0];

  if (first) {
    state.customer = first;
    $('customerChip').classList.remove('hidden');
    $('customerChip').textContent = `Customer: ${first.name} (${money(first.total_spent)})`;
  }
});

$('completeSale').addEventListener('click', async () => {
  const total = Number($('tTotal').textContent);
  const payments = state.payments.length
    ? state.payments
    : [{ method: $('payMethod').value, amount: Number($('payAmount').value || total) }];

  const { ok, payload } = await api('POST', '/api/sales', {
    items: state.cart.map((line) => ({ product_uuid: line.product_uuid, qty: line.qty, discount: line.discount })),
    payments,
    customer_id: state.customer?.id ?? null,
    bill_discount: Number($('billDiscount').value || 0),
  });

  if (!ok) {
    message(payload.message ?? 'The sale could not be completed.');
    return;
  }

  state.lastSale = payload.sale;
  state.cart = [];
  state.payments = [];
  state.customer = null;
  $('customerChip').classList.add('hidden');
  $('payAmount').value = '';
  $('billDiscount').value = 0;
  $('payments').innerHTML = '';
  renderCart();

  message(`Sale ${payload.sale.device_invoice_no} saved (${payload.sale.sync_state}).`);
  printReceipt(payload.sale);
  refreshSync();
});

$('payAmount').addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();

  const amount = Number(event.target.value);
  if (!(amount > 0)) return;

  state.payments.push({ method: $('payMethod').value, amount });
  event.target.value = '';
  renderPayments();
});

function renderPayments() {
  $('payments').innerHTML = state.payments
    .map((payment) => `<span class="pill">${payment.method} ${money(payment.amount)}</span>`)
    .join(' ');
}

function message(text) {
  $('sellMessage').textContent = text;
  setTimeout(() => { $('sellMessage').textContent = ''; }, 6000);
}

/* ── receipts & returns ─────────────────────────────────────────────────── */

$('findReceipt').addEventListener('click', async () => {
  const { payload } = await api('GET', `/api/sales/${encodeURIComponent($('receiptLookup').value)}`);
  const sale = payload.sale;

  if (!sale) {
    $('receiptDetail').innerHTML = '<p class="muted">No such receipt.</p>';
    return;
  }

  $('receiptDetail').innerHTML = `
    <h3>${sale.device_invoice_no} <small>${sale.sync_state}</small></h3>
    <p>${new Date(sale.sold_at).toLocaleString()} · total ${money(sale.total)} · refunded ${money(sale.refunded_amount)}</p>
    <table>${sale.items.map((item) => `<tr><td>${escapeHtml(item.name)}</td><td>${item.qty}</td><td>${money(item.line_total)}</td></tr>`).join('')}</table>
    <h4>Return lines</h4>
    <div id="returnLines">
      ${sale.items.map((item) => `<label class="check"><input type="checkbox" data-item="${item.id}" data-remaining="${item.qty - item.refunded_qty}" /> ${escapeHtml(item.name)} (max ${item.qty - item.refunded_qty})</label>`).join('')}
    </div>
    <button id="doRefund" class="btn primary">Refund selected</button>
    <p id="refundMessage" class="message"></p>`;

  $('doRefund').addEventListener('click', async () => {
    const lines = [...$('returnLines').querySelectorAll('input:checked')].map((input) => ({
      sale_item_id: Number(input.dataset.item),
      qty: Number(input.dataset.remaining),
    }));

    if (!lines.length) return;

    const { ok, payload } = await api('POST', `/api/sales/${sale.uuid}/refund`, { lines, reason: 'customer return' });
    $('refundMessage').textContent = ok ? `Refund of ${money(payload.refund.amount)} recorded.` : payload.message;
    refreshSync();
  });
});

/* ── drawer ─────────────────────────────────────────────────────────────── */

async function refreshDrawer() {
  const { payload } = await api('GET', '/api/session/current/totals');
  const panel = $('drawerPanel');
  const totals = payload.totals;

  if (!totals) {
    panel.innerHTML = `
      <h2>Drawer</h2>
      <p class="muted">No session is open on this till.</p>
      <div class="row"><input id="float" type="number" value="0" step="0.01" /><button id="openSession" class="btn primary">Open session</button></div>
      <p id="drawerMessage" class="message"></p>`;

    $('openSession').addEventListener('click', async () => {
      const { ok, payload: result } = await api('POST', '/api/session/open', { opening_float: Number($('float').value || 0) });
      if (!ok) $('drawerMessage').textContent = result.message;
      refreshDrawer();
    });

    return;
  }

  panel.innerHTML = `
    <h2>Drawer — open</h2>
    <div class="summary-grid">
      <div><small>Opening float</small><b>${money(totals.session.opening_float)}</b></div>
      <div><small>Cash sales</small><b>${money(totals.by_method.cash)}</b></div>
      <div><small>Card</small><b>${money(totals.by_method.card)}</b></div>
      <div><small>Mobile</small><b>${money(totals.by_method.mobile)}</b></div>
      <div><small>Refunds</small><b>${money(totals.refunds)}</b></div>
      <div><small>Expected cash</small><b>${money(totals.expected_cash)}</b></div>
      <div><small>Orders</small><b>${totals.orders}</b></div>
    </div>
    <div class="row">
      <select id="moveType"><option value="in">Cash in</option><option value="out">Cash out</option></select>
      <input id="moveAmount" type="number" step="0.01" placeholder="Amount" />
      <input id="moveReason" placeholder="Reason" />
      <button id="addMovement" class="btn">Record</button>
    </div>
    <div class="row">
      <input id="counted" type="number" step="0.01" placeholder="Counted cash" />
      <button id="closeSession" class="btn primary">Close session</button>
    </div>
    <p id="drawerMessage" class="message"></p>`;

  $('addMovement').addEventListener('click', async () => {
    const { ok, payload: result } = await api('POST', `/api/session/${totals.session.id}/movement`, {
      type: $('moveType').value,
      amount: Number($('moveAmount').value || 0),
      reason: $('moveReason').value,
    });

    if (!ok) $('drawerMessage').textContent = result.message;
    refreshDrawer();
    refreshSync();
  });

  $('closeSession').addEventListener('click', async () => {
    const { ok, payload: result } = await api('POST', `/api/session/${totals.session.id}/close`, {
      counted_cash: Number($('counted').value || 0),
    });

    if (!ok) $('drawerMessage').textContent = result.message;
    refreshDrawer();
    refreshSync();
  });
}

/* ── sync ───────────────────────────────────────────────────────────────── */

async function refreshSync() {
  const { payload } = await api('GET', '/api/status');
  state.status = payload.status;

  const s = payload.status;
  const labels = {
    offline: 'Offline', online: 'Online', syncing: 'Syncing…', synced: 'Synced',
    pending: 'Pending', failed: 'Failed', conflict: 'Conflict',
  };

  $('syncDot').className = `dot ${s.state}`;
  $('syncState').textContent = labels[s.state] ?? s.state;
  $('syncDetail').textContent = s.last_sync_at
    ? `last sync ${new Date(s.last_sync_at).toLocaleTimeString()} · pending ${s.pending} · failed ${s.failed}`
    : `never synced · pending ${s.pending}`;

  $('conflictBadge').textContent = String(s.conflicts);
  $('conflictBadge').classList.toggle('hidden', s.conflicts === 0);

  if ($('view-sync').classList.contains('active')) {
    $('syncSummary').innerHTML = `
      <div><small>State</small><b>${labels[s.state] ?? s.state}</b></div>
      <div><small>Connection</small><b>${(s.connectivity ?? '').replace(/_/g, ' ')}</b></div>
      <div><small>Last sync</small><b>${s.last_sync_at ? new Date(s.last_sync_at).toLocaleString() : 'never'}</b></div>
      <div><small>Pending</small><b>${s.pending}</b></div>
      <div><small>Failed</small><b>${s.failed}</b></div>
      <div><small>Conflicts</small><b>${s.conflicts}</b></div>
      <div><small>Cursor</small><b>${s.cursor}</b></div>
      <div><small>Last result</small><b>${s.last_sync_result ? `${s.last_sync_result.status}: ↑${s.last_sync_result.uploaded} ↓${s.last_sync_result.downloaded}` : '—'}</b></div>`;

    const queue = await api('GET', '/api/sync/queue');
    $('outbox').innerHTML = `<table><tr><th>#</th><th>Entity</th><th>Status</th><th>Attempts</th><th>Error</th></tr>
      ${(queue.payload.data ?? []).slice(0, 50).map((row) => `<tr><td>${row.id}</td><td>${row.entity_type}</td><td>${row.status}</td><td>${row.attempts}</td><td>${escapeHtml(row.error_message ?? '')}</td></tr>`).join('')}</table>`;

    $('syncLog').innerHTML = `<table><tr><th>When</th><th>Status</th><th>↑</th><th>↓</th><th>Duplicates</th><th>Conflicts</th><th>Failed</th></tr>
      ${(payload.log ?? []).map((row) => `<tr><td>${new Date(row.at).toLocaleString()}</td><td>${row.status}</td><td>${row.uploaded}</td><td>${row.downloaded}</td><td>${row.duplicates}</td><td>${row.conflicts}</td><td>${row.failed}</td></tr>`).join('')}</table>`;
  }
}

async function syncNow() {
  $('syncNow').disabled = true;
  $('progress').classList.remove('hidden');
  $('progressText').textContent = 'Starting…';

  const { payload } = await api('POST', '/api/sync/now');

  $('syncNow').disabled = false;
  $('progressText').textContent = payload.ok
    ? `Uploaded ${payload.uploaded}, downloaded ${payload.downloaded}, duplicates ${payload.duplicates}, conflicts ${payload.conflicts}, failed ${payload.failed}`
    : `Sync failed: ${payload.message ?? payload.status}`;

  setTimeout(() => $('progress').classList.add('hidden'), 8000);
  refreshSync();
}

$('syncNow').addEventListener('click', syncNow);
$('syncNow2').addEventListener('click', syncNow);

$('retryFailed').addEventListener('click', async () => {
  await api('POST', '/api/sync/retry', {});
  refreshSync();
});

$('probe').addEventListener('click', async () => {
  const { payload } = await api('POST', '/api/sync/probe');
  $('progress').classList.remove('hidden');
  $('progressText').textContent = payload.ok
    ? `Server reachable — sync available (server seq ${payload.status?.server_seq ?? '?'})`
    : `Not available: ${payload.connectivity.replace(/_/g, ' ')}${payload.error ? ` — ${payload.error}` : ''}`;
});

// Live progress from the engine (Uploading n/N, Downloading n/N).
function followProgress() {
  const stream = new EventSource('/api/sync/stream');
  stream.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.phase === 'push') {
      $('progress').classList.remove('hidden');
      $('progressText').textContent = `Uploading ${data.done ?? 0}/${data.total ?? 0}…`;
      $('progressBar').style.width = data.total ? `${Math.round(((data.done ?? 0) / data.total) * 100)}%` : '0%';
    }
    if (data.phase === 'pull') {
      $('progress').classList.remove('hidden');
      $('progressText').textContent = `Downloading ${data.downloaded ?? 0}…`;
    }
    if (data.phase === 'done') {
      $('progressText').textContent = `Uploaded ${data.uploaded}, downloaded ${data.downloaded}, conflicts ${data.conflicts}, failed ${data.failed}`;
      refreshSync();
    }
  };
  stream.onerror = () => { stream.close(); setTimeout(followProgress, 5000); };
}

/* ── conflicts ──────────────────────────────────────────────────────────── */

async function refreshConflicts() {
  const { payload } = await api('GET', '/api/conflicts');
  const rows = payload.data ?? [];

  $('conflictList').innerHTML = rows.length ? `<table>
    <tr><th>#</th><th>Entity</th><th>Local</th><th>Server</th><th>Date</th><th>Status</th></tr>
    ${rows.map((row) => `<tr>
      <td>${row.server_conflict_id ?? row.id}</td>
      <td>${row.entity_type}<br><small>${row.severity}</small></td>
      <td><small>${escapeHtml(truncate(row.local_payload))}</small></td>
      <td><small>${escapeHtml(truncate(row.server_payload))}</small></td>
      <td>${new Date(row.detected_at).toLocaleString()}</td>
      <td>${row.status}${row.resolution_note ? `<br><small>${escapeHtml(row.resolution_note)}</small>` : ''}</td>
    </tr>`).join('')}
  </table>` : '<p class="muted">No conflicts. Nothing was overwritten without a decision.</p>';
}

/* ── reports & settings ─────────────────────────────────────────────────── */

$('runReport').addEventListener('click', async () => {
  const query = new URLSearchParams();
  if ($('reportFrom').value) query.set('from', `${$('reportFrom').value}T00:00:00.000Z`);
  if ($('reportTo').value) query.set('to', `${$('reportTo').value}T23:59:59.999Z`);

  const { payload } = await api('GET', `/api/reports/summary?${query.toString()}`);
  const t = payload.totals;

  $('reportOut').innerHTML = `
    <div class="summary-grid">
      <div><small>Sales</small><b>${t.count}</b></div>
      <div><small>Total</small><b>${money(t.total)}</b></div>
      <div><small>Refunds</small><b>${money(t.refunds)}</b></div>
      <div><small>Synced</small><b>${t.synced.count} / ${money(t.synced.total)}</b></div>
      <div><small>Pending (local only)</small><b>${t.pending.count} / ${money(t.pending.total)}</b></div>
      <div><small>Needs attention</small><b>${t.problem.count}</b></div>
    </div>
    <h3>Payment mix</h3>
    <table>${payload.by_method.map((row) => `<tr><td>${row.method}</td><td>${row.count}</td><td>${money(row.total)}</td></tr>`).join('')}</table>
    <h3>Outbox</h3>
    <table>${payload.queue.map((row) => `<tr><td>${row.status}</td><td>${row.total}</td></tr>`).join('')}</table>`;
});

async function refreshSettings() {
  const [device, settings] = await Promise.all([api('GET', '/api/device'), api('GET', '/api/settings')]);
  const d = device.payload;

  $('devicePanel').innerHTML = `
    <div class="summary-grid">
      <div><small>Device ID</small><b>${d.device_id ?? 'not set'}</b></div>
      <div><small>Registered</small><b>${d.registered ? 'yes' : 'no'}</b></div>
      <div><small>Branch</small><b>${d.branch_id ?? '—'}</b></div>
      <div><small>Server</small><b>${escapeHtml(d.server_url ?? '')}</b></div>
    </div>`;

  const s = settings.payload;
  $('settingsPanel').innerHTML = `
    <label class="check"><input id="autoSync" type="checkbox" ${s.auto_sync ? 'checked' : ''} /> Automatic sync every</label>
    <div class="row"><input id="autoMinutes" type="number" min="1" value="${s.auto_sync_minutes}" /> minutes</div>
    <div class="row"><input id="serverUrl" value="${escapeHtml(s.server_url ?? '')}" /></div>
    <button id="saveSettings" class="btn">Save</button>`;

  $('saveSettings').addEventListener('click', async () => {
    await api('PUT', '/api/settings', {
      auto_sync: $('autoSync').checked,
      auto_sync_minutes: Number($('autoMinutes').value),
      server_url: $('serverUrl').value,
    });
    refreshSettings();
  });
}

$('backupNow').addEventListener('click', async () => {
  const { payload } = await api('POST', '/api/backup', {});
  $('backupMessage').textContent = payload.file
    ? `Backup written: ${payload.file} (${payload.counts.pending} unsynced record(s) included)`
    : payload.message;
});

/* ── helpers ────────────────────────────────────────────────────────────── */

function printReceipt(sale) {
  const lines = sale.items.map((item) => `${item.name} x${item.qty}  ${money(item.line_total)}`).join('\n');
  const receipt = `SoftCora POS\n${sale.device_invoice_no}\n${new Date(sale.sold_at).toLocaleString()}\n\n${lines}\n\nTOTAL ${money(sale.total)}\nPAID ${money(sale.paid)}`;
  console.log(receipt);
}

function truncate(value) {
  const text = String(value ?? '');
  return text.length > 90 ? `${text.slice(0, 90)}…` : text;
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[character]));
}

/* ── start ──────────────────────────────────────────────────────────────── */

(async function start() {
  const device = await api('GET', '/api/device');
  $('deviceLabel').textContent = device.payload.device_id ?? 'not registered';

  if (!state.token || !(await api('GET', '/api/auth/me')).ok) {
    showLogin();
    return;
  }

  await boot();
  followProgress();
  setInterval(refreshSync, 15000);
})();
