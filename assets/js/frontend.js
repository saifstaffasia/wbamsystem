/* WBAM System — staff front-end app (no wp-admin needed). Vanilla JS, hash views. */
(function () {
  const A = window.WBAMAPP || {};
  const root = document.getElementById('wbam-app');
  if (!root) return;
  let BOOT = { branches: [], statuses: [], rtypes: [], grades: [], parts: [], vendors: [] };
  const q = (s, el) => (el || document).querySelector(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (v) => '£' + Number(v || 0).toFixed(2);

  const api = async (path, opts = {}) => {
    const o = { headers: { 'X-WP-Nonce': A.nonce, 'Content-Type': 'application/json' }, ...opts };
    const r = await fetch(A.rest + path, o);
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.message || 'Request failed');
    return j;
  };
  const toast = (m, err) => {
    const d = document.createElement('div');
    d.className = 'wa-msg ' + (err ? 'err' : 'ok');
    d.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:200;box-shadow:0 8px 24px rgba(0,0,0,.18)';
    d.textContent = m;
    document.body.appendChild(d);
    setTimeout(() => d.remove(), 3800);
  };

  /* ---------- shell ---------- */
  const VIEWS = [['intake', 'Intake'], ['units', 'Units'], ['reconcile', 'Reconcile'], ['repairs', 'Repairs']];
  if (A.reports) VIEWS.unshift(['dash', 'Dashboard']);
  function shell() {
    root.innerHTML =
      '<div class="wa-top"><span class="wa-logo">WBAM System</span><nav>' +
      VIEWS.map(([k, l]) => `<a href="#${k}" data-v="${k}">${l}</a>`).join('') +
      `</nav><span class="wa-user">${esc(A.user)}${A.admin ? ` · <a href="${A.admin}">Admin</a>` : ''} · <a href="${A.logout}">Log out</a></span></div>` +
      '<div class="wa-main" id="wa-main"></div>';
  }
  function nav() {
    const v = (location.hash || '#' + VIEWS[0][0]).slice(1).split('/')[0];
    root.querySelectorAll('.wa-top nav a').forEach((a) => a.classList.toggle('on', a.dataset.v === v));
    (RENDER[v] || RENDER[VIEWS[0][0]])();
  }

  /* ---------- dashboard ---------- */
  async function dash() {
    const m = q('#wa-main');
    const range = (location.hash.split('/')[1] || 'today');
    m.innerHTML = '<div class="wa-card"><div class="wa-row">' +
      ['today', 'yesterday', 'wtd', 'mtd', 'qtd', 'ytd'].map((r) =>
        `<a class="wa-btn s ${r === range ? 'p' : ''}" href="#dash/${r}">${{ today: 'Today', yesterday: 'Yesterday', wtd: 'Week', mtd: 'Month', qtd: 'Quarter', ytd: 'Year' }[r]}</a>`).join('') +
      '<button class="wa-btn s a" id="wa-refresh">↻ Refresh from Shopify</button></div><div id="wa-rep">Loading…</div></div>';
    q('#wa-refresh').onclick = async (e) => {
      e.target.disabled = true; e.target.textContent = 'Refreshing…';
      try { await api('report/refresh', { method: 'POST' }); dash(); } catch (er) { toast(er.message, 1); e.target.disabled = false; }
    };
    try {
      const r = await api('report?range=' + range);
      const t = r.totals;
      q('#wa-rep').innerHTML =
        `<div class="wa-tiles">
          <div class="t"><span>Sales</span><b>${money(t.gross)}</b><small>${t.orders} order${t.orders === 1 ? '' : 's'}</small></div>
          <div class="t"><span>Refunds</span><b>${money(t.refunded)}</b><small>refunds dated in this range</small></div>
          <div class="t"><span>Net sales</span><b>${money(t.net)}</b><small>sales − refunds</small></div>
          <div class="t"><span>Gross profit</span><b>${money(t.gp)}</b><small>${t.gp_pct != null ? t.gp_pct + '% of net' : '—'}${t.untracked_cost_lines ? ' · ' + t.untracked_cost_lines + ' lines w/o cost' : ''}</small></div>
          <div class="t"><span>Buy-ins</span><b>${money(r.buyback.spend)}</b><small>${r.buyback.intake_units} device(s)</small></div>
          <div class="t"><span>Repairs</span><b>${money(r.repairs.revenue)}</b><small>parts ${money(r.repairs.parts_cost)}</small></div>
        </div>
        <div class="wa-cols2">
          <div><h3>Payments</h3><table class="wa-list">${Object.entries(r.tenders).map(([k, v]) => `<tr><td>${esc(k)}</td><td style="text-align:right">${money(v)}</td></tr>`).join('') || '<tr><td>None</td></tr>'}</table></div>
          <div><h3>Staff</h3><table class="wa-list">${r.staff.map((s) => `<tr><td>${esc(s.label)}</td><td>${s.orders_n}</td><td style="text-align:right">${money(s.sales)}</td><td style="text-align:right">GP ${money(s.gp)}</td></tr>`).join('') || '<tr><td>None</td></tr>'}</table></div>
        </div>
        <p class="wa-sub">Refunds count on the day the refund was made (same as Shopify). GP = line revenue − refunds − recorded costs.</p>`;
    } catch (e) { q('#wa-rep').innerHTML = `<div class="wa-msg err">${esc(e.message)}</div>`; }
  }

  /* ---------- intake ---------- */
  const I = { product: null, sel: {}, custom: false, timer: null };
  function sellerFields(req) {
    return `<h3>Seller details${req ? '' : ' (optional)'}</h3><div class="wa-grid">
      <label>Full legal name${req ? ' *' : ''}<input id="se-name"></label>
      <label>Mobile${req ? ' *' : ''}<input id="se-mobile" inputmode="tel"></label>
      <label>Date of birth<input id="se-dob" type="date"></label>
      <label>Email<input id="se-email" type="email"></label>
      <label>Address line 1<input id="se-a1"></label>
      <label>Address line 2<input id="se-a2"></label>
      <label>Postcode<input id="se-pc"></label>
      <label>Time at address<input id="se-taa" placeholder="e.g. 3 years"></label>
      <label>Photo ID type<select id="se-idt"><option value=""></option><option>Passport</option><option>Driving licence</option><option>BRP</option><option>National ID</option><option>Other</option></select></label>
      <label>ID ref / expiry<input id="se-idr"></label>
    </div>`;
  }
  function intake() {
    const m = q('#wa-main');
    m.innerHTML = `<div class="wa-card"><h2>Device intake</h2>
      <div class="wa-grid">
        <label>IMEI / serial *<input id="in-imei" autocomplete="off" placeholder="scan here…"></label>
        <label>Branch<select id="in-branch">${BOOT.branches.map((b) => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select></label>
      </div>
      <div id="in-cat">
        <h3>Model</h3>
        <label class="wa-field">Search — results appear as you type<input id="in-search" autocomplete="off"></label>
        <div class="wa-pick" id="in-models" style="margin-top:8px"></div>
        <div id="in-opts"></div>
      </div>
      <p><button class="wa-btn s" id="in-custom-btn">➕ Not in the list? Custom device</button></p>
      <div id="in-custom" style="display:none">
        <h3>Custom device</h3><div class="wa-grid">
          <label>Device name *<input id="cu-title" placeholder="Google Pixel 8 128GB Black"></label>
          <label>Grade<select id="cu-grade">${BOOT.grades.map((g) => `<option${g === 'Used (B - Very Good)' ? ' selected' : ''}>${g}</option>`).join('')}</select></label>
          <label>Selling price £ *<input id="cu-sell" type="number" step="0.01"></label>
        </div>
      </div>
      <h3>Purchase</h3><div class="wa-grid">
        <label id="in-price-l">Price paid £ *<input id="in-price" type="number" step="0.01"></label>
        <label>Source<select id="in-source"><option value="buyback">Buy-in (Cash sale from private seller)</option><option value="tradein">Trade-in (against a sale)</option><option value="supplier">Supplier Stock</option></select></label>
        <label>Paid by<select id="in-payout"><option value="cash">Cash</option><option value="bank">Bank transfer</option><option value="store_credit">Trade-in value / store credit</option></select></label>
        <label>Battery %<input id="in-batt" type="number" min="0" max="100"></label>
        <label>Stolen-check ref<input id="in-cm"></label>
        <label>Notes<input id="in-notes"></label>
      </div>
      <div id="in-bank" style="display:none"><h3>Bank transfer details</h3><div class="wa-grid">
        <label>Account name *<input id="bk-name"></label>
        <label>Sort code *<input id="bk-sort" placeholder="00-00-00" inputmode="numeric"></label>
        <label>Account number *<input id="bk-acct" inputmode="numeric"></label>
        <label>Payment reference<input id="bk-ref"></label>
      </div></div>
      <div id="in-seller"></div>
      <h3>Device extras (for the declaration)</h3><div class="wa-grid">
        <label>IMEI 2<input id="ex-imei2"></label>
        <label>Serial no.<input id="ex-serial"></label>
        <label>Network lock<select id="ex-lock"><option value="">—</option><option>Unlocked</option><option>Locked</option></select></label>
        <label>Accessories<input id="ex-acc"></label>
        <label style="grid-column:1/-1">Known faults / damage<input id="ex-faults"></label>
      </div>
      <p class="wa-row"><button class="wa-btn p" id="in-save">Save intake (+1 stock)</button><span id="in-msg"></span></p>
      <div id="in-done"></div></div>`;

    const sellerBox = q('#in-seller');
    const syncSeller = () => { sellerBox.innerHTML = sellerFields(q('#in-source').value !== 'supplier'); };
    syncSeller();
    q('#in-source').onchange = () => {
      if (q('#in-source').value === 'tradein') q('#in-payout').value = 'store_credit';
      q('#in-price-l').firstChild.textContent = q('#in-source').value === 'tradein' ? 'Trade-in allowance £ *' : 'Price paid £ *';
      syncSeller(); syncBank();
    };
    const syncBank = () => { q('#in-bank').style.display = q('#in-payout').value === 'bank' ? '' : 'none'; };
    q('#in-payout').onchange = syncBank;

    q('#in-search').oninput = (e) => {
      clearTimeout(I.timer);
      const v = e.target.value.trim();
      if (v.length < 2) return;
      I.timer = setTimeout(async () => {
        try {
          const models = await api('models?term=' + encodeURIComponent(v));
          q('#in-models').innerHTML = models.map((mo, i) => `<button data-i="${i}">${esc(mo.title)}</button>`).join('');
          q('#in-models')._models = models;
        } catch (er) {}
      }, 350);
    };
    q('#in-models').onclick = (e) => {
      const b = e.target.closest('button'); if (!b) return;
      I.product = q('#in-models')._models[+b.dataset.i]; I.sel = {};
      q('#in-models').querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
      q('#in-opts').innerHTML = Object.entries(I.product.options).map(([n, vals]) =>
        `<h3>${esc(n)}</h3><div class="wa-pick" data-opt="${esc(n)}">${vals.map((v) => `<button data-v="${esc(v)}">${esc(v)}</button>`).join('')}</div>`).join('');
    };
    q('#in-opts').onclick = (e) => {
      const b = e.target.closest('button'); if (!b) return;
      const g = b.closest('.wa-pick'); I.sel[g.dataset.opt] = b.dataset.v;
      g.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
    };
    q('#in-custom-btn').onclick = () => {
      I.custom = !I.custom; I.product = null; I.sel = {};
      q('#in-custom').style.display = I.custom ? '' : 'none';
      q('#in-cat').style.display = I.custom ? 'none' : '';
      q('#in-custom-btn').textContent = I.custom ? '← Back to catalog search' : '➕ Not in the list? Custom device';
    };
    q('#in-save').onclick = async () => {
      const msg = q('#in-msg'); msg.textContent = '';
      const val = (id) => q(id).value.trim();
      const seller = { name: val('#se-name'), mobile: val('#se-mobile'), dob: val('#se-dob'), email: val('#se-email'),
        address1: val('#se-a1'), address2: val('#se-a2'), postcode: val('#se-pc'), time_at_address: val('#se-taa'),
        id_type: val('#se-idt'), id_ref: val('#se-idr'),
        imei2: val('#ex-imei2'), serial: val('#ex-serial'), network_lock: val('#ex-lock'), accessories: val('#ex-acc'), known_faults: val('#ex-faults') };
      const body = { imei: val('#in-imei'), branch_id: +val('#in-branch'), purchase_price: parseFloat(val('#in-price') || '0'),
        source: val('#in-source'), payout_method: val('#in-payout'), battery_health: val('#in-batt'), checkmend_ref: val('#in-cm'),
        notes: val('#in-notes'), seller };
      if (val('#in-payout') === 'bank') {
        body.bank = { account_name: val('#bk-name'), sort_code: val('#bk-sort'), account_number: val('#bk-acct'), reference: val('#bk-ref') };
        if (!body.bank.account_name || !body.bank.sort_code || !body.bank.account_number) { msg.textContent = '⚠ Bank details incomplete.'; return; }
      }
      if (body.source !== 'supplier' && (!seller.name || !seller.mobile)) { msg.textContent = '⚠ Seller name and mobile are required.'; return; }
      if (I.custom) {
        Object.assign(body, { custom: 1, title: val('#cu-title'), grade: val('#cu-grade'), sell_price: parseFloat(val('#cu-sell') || '0') });
        if (!body.title || !(body.sell_price > 0)) { msg.textContent = '⚠ Custom device needs a name and selling price.'; return; }
      } else {
        if (!I.product) { msg.textContent = '⚠ Pick the model (or use Custom device).'; return; }
        if (!Object.keys(I.product.options).every((n) => I.sel[n])) { msg.textContent = '⚠ Pick every option.'; return; }
        Object.assign(body, { product_id: I.product.product_id, model_title: I.product.title, selected: I.sel });
      }
      const btn = q('#in-save'); btn.disabled = true; btn.textContent = 'Saving…';
      try {
        const r = await api('intake', { method: 'POST', body: JSON.stringify(body) });
        q('#in-done').innerHTML = `<div class="wa-msg ok">${esc(r.unit.unit_code)} saved — ${esc(r.unit.model_title)} ${esc(r.unit.variant_title)}</div>
          <p class="wa-row"><a class="wa-btn a" target="_blank" href="${r.unit.label_url}">🏷 Print label</a>
          <a class="wa-btn" target="_blank" href="${r.unit.declaration_url}">📄 Seller declaration</a></p>`;
        window.open(r.unit.label_url, '_blank');
        ['#in-imei', '#in-price', '#in-batt', '#in-cm', '#in-notes', '#ex-imei2', '#ex-serial', '#ex-acc', '#ex-faults'].forEach((s) => { q(s).value = ''; });
        sellerBox.querySelectorAll('input,select').forEach((el) => { el.value = ''; });
        q('#in-imei').focus();
      } catch (er) { msg.textContent = '⚠ ' + er.message; }
      btn.disabled = false; btn.textContent = 'Save intake (+1 stock)';
    };
    q('#in-imei').focus();
  }

  /* ---------- units ---------- */
  async function units() {
    const m = q('#wa-main');
    m.innerHTML = `<div class="wa-card"><h2>Units</h2>
      <div class="wa-row"><input id="un-q" placeholder="scan / IMEI / code / model…" style="max-width:300px">
      <select id="un-st"><option value="in_stock">In stock</option><option value="sold">Sold</option><option value="written_off">Written off</option><option value="">Any</option></select>
      <select id="un-br"><option value="0">All branches</option>${BOOT.branches.map((b) => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select>
      <button class="wa-btn s p" id="un-go">Search</button></div>
      <div id="un-list" style="margin-top:10px">Loading…</div></div>`;
    const load = async () => {
      const rows = await api(`app/units?q=${encodeURIComponent(q('#un-q').value)}&status=${q('#un-st').value}&branch=${q('#un-br').value}`);
      q('#un-list').innerHTML = `<table class="wa-list"><tr><th>Unit</th><th>Device</th><th>IMEI</th><th>Status</th><th>Paid</th><th>Seller</th><th></th></tr>` +
        rows.map((u) => `<tr>
          <td><b>${esc(u.unit_code)}</b><br><small class="wa-sub">${esc((u.created_at || '').slice(0, 10))}</small></td>
          <td>${esc(u.model_title)}<br><small class="wa-sub">${esc(u.variant_title)}</small></td>
          <td>…${esc(String(u.imei).slice(-8))}</td>
          <td><span class="wa-chip ${esc(u.status)}">${esc(u.status.replace('_', ' '))}</span>${u.status === 'sold' ? `<br><small class="wa-sub">${esc(u.order_name)} ${money(u.sale_price)}</small>` : ''}</td>
          <td>${money(u.purchase_price)}</td>
          <td>${esc(u.seller_name || '—')}</td>
          <td class="wa-row">
            <a class="wa-btn s" target="_blank" href="${u.label_url}">Label</a>
            <a class="wa-btn s" target="_blank" href="${u.declaration_url}">Declaration</a>
            <button class="wa-btn s a" data-edit="${u.id}">Edit</button>
            ${u.status === 'sold' ? `<button class="wa-btn s" data-op="return" data-id="${u.id}">Return</button>` : ''}
            ${u.status === 'in_stock' && A.manage ? `<button class="wa-btn s danger" data-op="writeoff" data-id="${u.id}">Write off</button>` : ''}
          </td></tr>`).join('') + '</table>';
      q('#un-list')._rows = rows;
    };
    q('#un-go').onclick = load;
    q('#un-q').onkeydown = (e) => { if (e.key === 'Enter') load(); };
    q('#un-list').onclick = async (e) => {
      const ed = e.target.closest('[data-edit]');
      if (ed) { editUnit(q('#un-list')._rows.find((u) => u.id == ed.dataset.edit)); return; }
      const op = e.target.closest('[data-op]');
      if (op) {
        const reason = op.dataset.op === 'writeoff' ? prompt('Reason for write-off?') : '';
        if (op.dataset.op === 'writeoff' && reason === null) return;
        try { await api(`unit/${op.dataset.id}/${op.dataset.op}`, { method: 'POST', body: JSON.stringify({ reason }) }); toast('Done'); load(); }
        catch (er) { toast(er.message, 1); }
      }
    };
    load();
  }
  function editUnit(u) {
    const s = u.seller || {};
    const d = document.createElement('div');
    d.className = 'wa-modal';
    d.innerHTML = `<div class="wa-card"><h2>Edit ${esc(u.unit_code)}</h2><div class="wa-grid">
      <label>IMEI / serial<input id="ed-imei" value="${esc(u.imei)}"></label>
      <label>Price paid £<input id="ed-price" type="number" step="0.01" value="${esc(u.purchase_price)}"></label>
      <label>Battery %<input id="ed-batt" type="number" value="${esc(u.battery_health ?? '')}"></label>
      <label>Stolen-check ref<input id="ed-cm" value="${esc(u.checkmend_ref)}"></label>
      <label>Seller name<input id="ed-sname" value="${esc(u.seller_name)}"></label>
      <label>Seller mobile<input id="ed-smob" value="${esc(s.mobile || '')}"></label>
      <label>Address 1<input id="ed-sa1" value="${esc(s.address1 || '')}"></label>
      <label>Postcode<input id="ed-spc" value="${esc(s.postcode || '')}"></label>
      <label>ID type<input id="ed-sidt" value="${esc(s.id_type || '')}"></label>
      <label>ID ref<input id="ed-sidr" value="${esc(s.id_ref || '')}"></label>
      <label style="grid-column:1/-1">Notes<input id="ed-notes" value="${esc(u.notes || '')}"></label>
      </div><p class="wa-row"><button class="wa-btn p" id="ed-save">Save</button><button class="wa-btn" id="ed-x">Cancel</button><span id="ed-msg"></span></p></div>`;
    document.body.appendChild(d);
    q('#ed-x', d).onclick = () => d.remove();
    q('#ed-save', d).onclick = async () => {
      try {
        await api(`app/unit/${u.id}/edit`, { method: 'POST', body: JSON.stringify({
          imei: q('#ed-imei', d).value, purchase_price: q('#ed-price', d).value, battery_health: q('#ed-batt', d).value,
          checkmend_ref: q('#ed-cm', d).value, notes: q('#ed-notes', d).value, seller_name: q('#ed-sname', d).value,
          seller: { name: q('#ed-sname', d).value, mobile: q('#ed-smob', d).value, address1: q('#ed-sa1', d).value,
                    postcode: q('#ed-spc', d).value, id_type: q('#ed-sidt', d).value, id_ref: q('#ed-sidr', d).value },
        }) });
        toast('Saved'); d.remove(); nav();
      } catch (er) { q('#ed-msg', d).textContent = '⚠ ' + er.message; }
    };
  }

  /* ---------- reconcile ---------- */
  async function reconcile() {
    const m = q('#wa-main');
    m.innerHTML = '<div class="wa-card"><h2>Reconcile sold units</h2><p class="wa-sub">These sold lines match a serialized model but more than one unit was in stock — scan the label of the phone that actually left.</p><div id="rc-list">Loading…</div></div>';
    const rows = await api('app/reconcile');
    q('#rc-list').innerHTML = rows.length ? rows.map((r) => `
      <div class="wa-card"><b>${esc(r.order_name)}</b> · ${esc(r.title)}<br>
      <small class="wa-sub">Candidates: ${r.candidates.map((c) => `${c.unit_code} (…${String(c.imei).slice(-4)})`).join(', ') || '—'}</small>
      <div class="wa-row" style="margin-top:8px"><input placeholder="scan unit code / IMEI" data-line="${r.id}" style="max-width:260px">
      <button class="wa-btn s p" data-attach="${r.id}">Attach</button></div></div>`).join('')
      : '<div class="wa-msg ok">All clear 🎉</div>';
    q('#rc-list').onclick = async (e) => {
      const b = e.target.closest('[data-attach]'); if (!b) return;
      const inp = q(`[data-line="${b.dataset.attach}"]`);
      try { await api('app/reconcile/attach', { method: 'POST', body: JSON.stringify({ line_row: +b.dataset.attach, unit_scan: inp.value }) }); toast('Attached — IMEI stamped on the order'); reconcile(); }
      catch (er) { toast(er.message, 1); }
    };
  }

  /* ---------- repairs ---------- */
  async function repairs() {
    const id = +(location.hash.split('/')[1] || 0);
    if (id) return ticket(id);
    const m = q('#wa-main');
    m.innerHTML = `<div class="wa-card"><h2>Repairs</h2>
      <div class="wa-row"><input id="tk-q" placeholder="ticket / name / phone / IMEI…" style="max-width:300px">
      <label class="wa-row" style="font-weight:600;font-size:13px"><input type="checkbox" id="tk-all" style="width:auto"> incl. closed</label>
      <button class="wa-btn s p" id="tk-go">Search</button><a class="wa-btn s a" href="#repairs/new" id="tk-new">➕ New walk-in</a></div>
      <div id="tk-list" style="margin-top:10px">Loading…</div></div>`;
    const load = async () => {
      const rows = await api(`app/tickets?q=${encodeURIComponent(q('#tk-q').value)}&all=${q('#tk-all').checked ? 1 : ''}`);
      q('#tk-list').innerHTML = `<table class="wa-list"><tr><th>Ticket</th><th>Customer</th><th>Device</th><th>Status</th><th>Type · Due</th><th>Quote</th></tr>` +
        rows.map((t) => `<tr style="cursor:pointer" onclick="location.hash='#repairs/${t.id}'">
          <td><b>${esc(t.ticket_code)}</b></td><td>${esc(t.customer_name)}<br><small class="wa-sub">${esc(t.phone)}</small></td>
          <td>${esc(t.device_model)}${+t.device_held ? '' : ' 📵'}</td>
          <td><span class="wa-chip ${esc(t.status)}">${esc(t.status.replace(/_/g, ' '))}</span></td>
          <td>${esc(t.repair_type || '—')}${t.due_date ? `<br><small class="wa-sub">due ${esc(t.due_date)}</small>` : ''}</td>
          <td>${t.quote != null ? money(t.quote) : '—'}</td></tr>`).join('') + '</table>';
    };
    q('#tk-go').onclick = load;
    q('#tk-q').onkeydown = (e) => { if (e.key === 'Enter') load(); };
    q('#tk-new').onclick = (e) => { e.preventDefault(); newTicket(); };
    load();
  }
  function newTicket() {
    const d = document.createElement('div');
    d.className = 'wa-modal';
    d.innerHTML = `<div class="wa-card"><h2>New walk-in repair</h2><div class="wa-grid">
      <label>Branch<select id="nt-br">${BOOT.branches.map((b) => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select></label>
      <label>Customer *<input id="nt-name"></label><label>Phone *<input id="nt-phone"></label>
      <label>Email<input id="nt-email"></label><label>Device *<input id="nt-dev"></label>
      <label>IMEI<input id="nt-imei"></label><label>Passcode<input id="nt-pass"></label>
      <label>Repair type<select id="nt-rt"><option value=""></option>${BOOT.rtypes.map((r) => `<option>${r}</option>`).join('')}</select></label>
      <label>Est. completion<input id="nt-due" type="date"></label>
      <label>Device left with us?<select id="nt-held"><option value="1">Yes — device is here</option><option value="0">No — comes later</option></select></label>
      <label>Quote £<input id="nt-quote" type="number" step="0.01"></label>
      <label style="grid-column:1/-1">Fault *<textarea id="nt-fault" rows="2"></textarea></label>
      </div><p class="wa-row"><button class="wa-btn p" id="nt-save">Create ticket</button><button class="wa-btn" id="nt-x">Cancel</button><span id="nt-msg"></span></p></div>`;
    document.body.appendChild(d);
    q('#nt-due', d).value = new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10);
    q('#nt-x', d).onclick = () => d.remove();
    q('#nt-save', d).onclick = async () => {
      try {
        const r = await api('app/ticket/new', { method: 'POST', body: JSON.stringify({
          branch_id: +q('#nt-br', d).value, customer_name: q('#nt-name', d).value, phone: q('#nt-phone', d).value,
          email: q('#nt-email', d).value, device_model: q('#nt-dev', d).value, imei: q('#nt-imei', d).value,
          passcode: q('#nt-pass', d).value, repair_type: q('#nt-rt', d).value, due_date: q('#nt-due', d).value,
          device_held: +q('#nt-held', d).value, quote: q('#nt-quote', d).value, fault: q('#nt-fault', d).value,
        }) });
        d.remove(); location.hash = '#repairs/' + r.ticket.id;
      } catch (er) { q('#nt-msg', d).textContent = '⚠ ' + er.message; }
    };
  }
  async function ticket(id) {
    const m = q('#wa-main');
    m.innerHTML = '<div class="wa-card">Loading…</div>';
    const t = await api('app/ticket/' + id);
    const e = t.economics;
    m.innerHTML = `<p><a href="#repairs">← all repairs</a></p>
      <div class="wa-card"><h2>${esc(t.ticket_code)} — ${esc(t.device_model)} <span class="wa-chip ${esc(t.status)}">${esc(t.status.replace(/_/g, ' '))}</span></h2>
      <p class="wa-sub"><b>${esc(t.customer_name)}</b> · ${esc(t.phone)} · ${esc(t.email || '')} · IMEI ${esc(t.imei || '—')} · ${+t.device_held ? 'device with us' : '📵 customer has device'}</p>
      <p><b>Fault:</b> ${esc(t.fault || '')}</p>
      <h3>Move status</h3><div class="wa-pick" id="tk-st">${BOOT.statuses.map((s) => `<button data-s="${s}" class="${s === t.status ? 'on' : ''}">${s.replace(/_/g, ' ')}</button>`).join('')}</div>
      <p class="wa-sub">Tapping a status updates the customer automatically where relevant.</p>
      <h3>Details</h3><div class="wa-grid">
        <label>Diagnosis<textarea id="tk-diag" rows="2">${esc(t.diagnosis || '')}</textarea></label>
        <label>Quote £<input id="tk-quote" type="number" step="0.01" value="${esc(t.quote ?? '')}"></label>
        <label>Type<select id="tk-rt"><option value=""></option>${BOOT.rtypes.map((r) => `<option${r === t.repair_type ? ' selected' : ''}>${r}</option>`).join('')}</select></label>
        <label>Est. completion<input id="tk-due" type="date" value="${esc(t.due_date || '')}"></label>
        <label>Device<select id="tk-held"><option value="1"${+t.device_held ? ' selected' : ''}>with us</option><option value="0"${+t.device_held ? '' : ' selected'}>customer has it</option></select></label>
      </div><p><button class="wa-btn p" id="tk-save">Save details</button></p>
      <h3>Money</h3><p>Quoted ${money(e.quoted)} · paid ${money(e.paid)} · parts ${money(e.parts)} · <b>margin ${money(e.margin)}</b></p>
      <div class="wa-row"><select id="tk-which"><option value="deposit">deposit</option><option value="balance">balance</option></select>
      <input id="tk-amt" type="number" step="0.01" placeholder="£" style="max-width:120px">
      <button class="wa-btn s a" id="tk-draft">Create payment link / POS draft</button></div>
      <p class="wa-sub">In store the POS Repairs tile is quickest — this is for remote payment or drafts.</p>
      <h3>Parts used</h3>${t.parts.length ? '<ul>' + t.parts.map((p) => `<li>${esc(p.description)} ×${p.qty} — ${money(p.unit_cost)} <small class="wa-sub">(${esc(p.source)})</small></li>`).join('') + '</ul>' : '<p class="wa-sub">None yet.</p>'}
      <div class="wa-row"><select id="tk-part">${BOOT.parts.map((p) => `<option value="${p.id}">${esc(p.name)} (${p.total_qty} in stock)</option>`).join('')}</select>
      <input id="tk-pqty" type="number" value="1" min="1" style="max-width:80px"><button class="wa-btn s" id="tk-usepart">Use from stock</button></div>
      <div class="wa-row" style="margin-top:8px"><select id="tk-vendor">${BOOT.vendors.map((v) => `<option value="${v.id}">${esc(v.name)}</option>`).join('')}</select>
      <input id="tk-podesc" placeholder="part description" style="max-width:220px"><input id="tk-pocost" type="number" step="0.01" placeholder="£" style="max-width:100px">
      <button class="wa-btn s" id="tk-order">Order from vendor</button></div>
      <h3>Log</h3><ul class="wa-log">${t.events.map((ev) => `<li><small class="wa-sub">${esc((ev.created_at || '').slice(5, 16))}</small> <b>${esc(ev.event)}</b> ${esc(ev.detail || '')}</li>`).join('')}</ul></div>`;
    q('#tk-st').onclick = async (ev) => {
      const b = ev.target.closest('button'); if (!b || b.dataset.s === t.status) return;
      const note = ['diagnosed', 'awaiting_parts'].includes(b.dataset.s) ? (prompt('Optional note for the customer message:') || '') : '';
      try { await api(`app/ticket/${id}/status`, { method: 'POST', body: JSON.stringify({ status: b.dataset.s, note }) }); toast('Status updated'); ticket(id); }
      catch (er) { toast(er.message, 1); }
    };
    q('#tk-save').onclick = async () => {
      try { await api(`app/ticket/${id}/save`, { method: 'POST', body: JSON.stringify({
        diagnosis: q('#tk-diag').value, quote: q('#tk-quote').value, repair_type: q('#tk-rt').value,
        due_date: q('#tk-due').value, device_held: +q('#tk-held').value }) }); toast('Saved'); }
      catch (er) { toast(er.message, 1); }
    };
    q('#tk-draft').onclick = async () => {
      try { const r = await api(`app/ticket/${id}/draft`, { method: 'POST', body: JSON.stringify({ which: q('#tk-which').value, amount: q('#tk-amt').value }) });
        prompt('Payment link (also in POS → Draft orders):', r.url); }
      catch (er) { toast(er.message, 1); }
    };
    q('#tk-usepart').onclick = async () => {
      try { await api(`app/ticket/${id}/part-stock`, { method: 'POST', body: JSON.stringify({ part_id: +q('#tk-part').value, qty: +q('#tk-pqty').value }) }); toast('Part booked'); ticket(id); }
      catch (er) { toast(er.message, 1); }
    };
    q('#tk-order').onclick = async () => {
      try { await api(`app/ticket/${id}/part-order`, { method: 'POST', body: JSON.stringify({ vendor_id: +q('#tk-vendor').value, description: q('#tk-podesc').value, unit_cost: q('#tk-pocost').value, qty: 1 }) });
        toast('Draft PO created — order on the supplier site, then Mark ordered in admin'); ticket(id); }
      catch (er) { toast(er.message, 1); }
    };
  }

  const RENDER = { dash, intake, units, reconcile, repairs };

  (async () => {
    try { BOOT = await api('app/bootstrap'); } catch (e) {}
    shell();
    window.addEventListener('hashchange', nav);
    nav();
  })();
})();
