import {render} from 'preact';
import {useState, useRef, useEffect} from 'preact/hooks';

// Interim host (WP Engine) — switch to https://system.webuyanymobile.com and redeploy.
const HUB = 'https://system.webuyanymobile.com';

export default async () => {
  render(<Extension />, document.body);
};

/**
 * Device intake at the till: catalog or custom device, seller details
 * (mirrors the paper Seller Declaration), bank payout details when paid by
 * transfer, and automatic trade-in ↔ sale linking via a cart attribute.
 */
function Extension() {
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');
  const [view, setView] = useState('form'); // form | list | bench

  // intaken-device list + bench
  const [lq, setLq] = useState('');
  const [units, setUnits] = useState(null);
  const [bench, setBench] = useState(null);   // /pos/unit payload
  const [pTerm, setPTerm] = useState('');     // part search
  const [parts, setParts] = useState([]);
  const [wDesc, setWDesc] = useState('');
  const [wKind, setWKind] = useState('part');
  const [wQty, setWQty] = useState('1');
  const [wCost, setWCost] = useState('');
  const [nGrade, setNGrade] = useState('');
  const [nPrice, setNPrice] = useState('');

  const [imei, setImei] = useState('');
  const [search, setSearch] = useState('');
  const [models, setModels] = useState([]);
  const [product, setProduct] = useState(null);
  const [sel, setSel] = useState({});
  const [custom, setCustom] = useState(false);
  const [cTitle, setCTitle] = useState('');
  const [cGrade, setCGrade] = useState('Used (B - Very Good)');
  const [cSell, setCSell] = useState('');

  const [price, setPrice] = useState('');
  const [sellPrice, setSellPrice] = useState('');
  const [source, setSource] = useState('buyback');
  const [payout, setPayout] = useState('cash');
  const [battery, setBattery] = useState('');

  // bank payout
  const [bkName, setBkName] = useState('');
  const [bkSort, setBkSort] = useState('');
  const [bkAcct, setBkAcct] = useState('');

  // supplier stock
  const [supplier, setSupplier] = useState('');

  // seller
  const [sName, setSName] = useState('');
  const [sMobile, setSMobile] = useState('');
  const [sDob, setSDob] = useState('');
  const [sAddr, setSAddr] = useState('');
  const [sPostcode, setSPostcode] = useState('');
  const [sEmail, setSEmail] = useState('');
  const [sIdType, setSIdType] = useState('');
  const [sIdRef, setSIdRef] = useState('');

  const timer = useRef(null);
  const grab = (set) => (e) => set(e && e.currentTarget ? e.currentTarget.value : '');

  const call = async (path, opts = {}) => {
    const token = await shopify.session.getSessionToken();
    const ctl = new AbortController();
    const kill = setTimeout(() => ctl.abort(), 15000);
    let res, json;
    try {
      res = await fetch(`${HUB}/wp-json/wbam/v1/pos/${path}`, {
        ...opts,
        signal: ctl.signal,
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(opts.headers || {}) },
      });
      json = await res.json();
    } catch (e) {
      throw new Error(ctl.signal.aborted ? 'No response from the Hub — try again.' : 'Connection problem — try again.');
    } finally {
      clearTimeout(kill);
    }
    if (!res.ok) throw new Error((json && json.message) || `HTTP ${res.status}`);
    return json;
  };

  const searchSeq = useRef(0);
  const doSearch = async (term) => {
    if (!term || term.trim().length < 2) return;
    const mine = ++searchSeq.current;
    try {
      const found = await call(`models?term=${encodeURIComponent(term.trim())}`);
      if (mine === searchSeq.current) setModels(found);
    } catch (e) {
      if (mine === searchSeq.current) setMsg('Search failed — type again. (' + String((e && e.message) || e) + ')');
    }
  };
  const onSearchInput = (e) => {
    const v = e && e.currentTarget ? e.currentTarget.value : '';
    setSearch(v);
    setMsg('');
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => doSearch(v), 400);
  };
  const chooseSource = (s) => { setSource(s); if (s === 'tradein') setPayout('store_credit'); };
  const optionsComplete = () => product && Object.keys(product.options || {}).every((n) => sel[n]);

  // Read-only list of intaken devices (recent, or search IMEI / unit code / model).
  const loadUnits = async (term) => {
    setBusy(true);
    setMsg('');
    try {
      setUnits(await call(`units?q=${encodeURIComponent(term || '')}`));
    } catch (e) {
      setMsg(String((e && e.message) || e));
    }
    setBusy(false);
  };
  const openList = () => { setMsg(''); setView('list'); if (units === null) loadUnits(''); };

  // Refurb bench on the till: same engine as the Hub screens.
  const openBench = async (u) => {
    setMsg('');
    try {
      const info = await call(`unit?id=${u.id}`);
      setBench(info);
      setNGrade(info.grade || 'Used (A - Excellent)');
      setNPrice('');
      setView('bench');
    } catch (e) { setMsg(String((e && e.message) || e)); }
  };
  const refurb = async (body, toastMsg) => {
    setBusy(true);
    setMsg('');
    try {
      const r = await call('refurb', { method: 'POST', body: JSON.stringify({ unit_id: bench.id, ...body }) });
      setBench(r.unit);
      if (toastMsg) shopify.toast.show(toastMsg);
      return r.unit;
    } catch (e) { setMsg(String((e && e.message) || e)); return null; }
    finally { setBusy(false); }
  };
  const searchParts = async (term) => {
    try { setParts(await call(`parts?term=${encodeURIComponent(term || '')}`)); } catch (e) {}
  };

  // Every option picked → prefill Selling price with the current shelf price.
  const priceSeq = useRef(0);
  useEffect(() => {
    if (custom || !product || !optionsComplete()) return;
    const mine = ++priceSeq.current;
    (async () => {
      try {
        const r = await call(`price?product_id=${product.product_id}&selected=${encodeURIComponent(JSON.stringify(sel))}`);
        if (mine !== priceSeq.current) return;
        setSellPrice(r && r.price != null ? String(r.price) : '');
      } catch (e) {}
    })();
  }, [product, sel, custom]);

  const submit = async () => {
    if (custom) {
      if (!cTitle.trim()) { setMsg('Enter the device name.'); return; }
      if (!(parseFloat(cSell) > 0)) { setMsg('Enter the selling price.'); return; }
    } else {
      if (!product) { setMsg('Pick the model (or use Custom device).'); return; }
      if (!optionsComplete()) { setMsg('Pick every option.'); return; }
    }
    if (price === '' || !(parseFloat(price) >= 0)) { setMsg('Enter the price paid.'); return; }
    if (source !== 'supplier') {
      if (!sName.trim() || !sMobile.trim()) { setMsg('Seller name and mobile are required.'); return; }
      // The purchase receipt is emailed to the seller — a valid email is required.
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(sEmail.trim())) {
        setMsg('Enter a valid seller email — their receipt is sent there.');
        return;
      }
    }
    setBusy(true);
    setMsg('');
    try {
      const body = {
        imei,
        purchase_price: parseFloat(price),
        source,
        payout_method: payout,
        battery_health: battery,
        location_id: shopify.session.currentSession.locationId,
        seller: {
          name: sName, mobile: sMobile, dob: sDob, address1: sAddr, postcode: sPostcode,
          email: sEmail.trim(), id_type: sIdType, id_ref: sIdRef,
        },
      };
      if (payout === 'bank') body.bank = { account_name: bkName, sort_code: bkSort, account_number: bkAcct };
      if (source === 'supplier' && supplier.trim()) body.source_ref = supplier.trim();
      if (!custom && sellPrice !== '' && parseFloat(sellPrice) > 0) body.price_override = parseFloat(sellPrice);
      if (custom) Object.assign(body, { custom: 1, title: cTitle.trim(), grade: cGrade, sell_price: parseFloat(cSell) });
      else Object.assign(body, { product_id: product.product_id, model_title: product.title, selected: sel });

      const res = await call('intake', { method: 'POST', body: JSON.stringify(body) });

      if (source === 'tradein') {
        const allowance = parseFloat(price).toFixed(2);
        try {
          await shopify.cart.addCartProperties({
            [`Trade-in ${res.unit_code}`]: `${res.title} — IMEI ${res.imei || imei} — allowance £${allowance}`,
          });
        } catch (e) {}
        shopify.toast.show(`${res.unit_code} saved${res.receipt ? ' — receipt emailed' : ''}. Take £${allowance} on the Trade In payment method`);
      } else {
        shopify.toast.show(`${res.unit_code} added to stock${res.receipt ? ' — receipt emailed' : ''}`);
      }
      setImei(''); setSearch(''); setModels([]); setProduct(null); setSel({});
      setCustom(false); setCTitle(''); setCGrade('Used (B - Very Good)'); setCSell('');
      setPrice(''); setSellPrice(''); setSource('buyback'); setPayout('cash'); setBattery('');
      setBkName(''); setBkSort(''); setBkAcct(''); setSupplier('');
      setSName(''); setSMobile(''); setSDob(''); setSAddr(''); setSPostcode(''); setSEmail(''); setSIdType(''); setSIdRef('');
    } catch (e) {
      setMsg(String((e && e.message) || e));
    }
    setBusy(false);
  };

  return (
    <s-page heading="Trade In Device Intake">
      <s-scroll-box>
        <s-box padding="small">
          {msg ? <s-box padding="small"><s-text>{msg}</s-text></s-box> : null}

          {view !== 'bench' ? (
            <s-box padding="small">
              <s-button onClick={() => (view === 'list' ? (setMsg(''), setView('form')) : openList())}>
                {view === 'list' ? '← Back to intake' : '📋 View intaken devices'}
              </s-button>
            </s-box>
          ) : null}

          {view === 'bench' && bench ? (
            <s-box>
              <s-box padding="small"><s-button onClick={() => { setMsg(''); setView('list'); loadUnits(lq); }}>← Back to device list</s-button></s-box>
              <s-box padding="small"><s-text>{`🔧 ${bench.unit} · ${bench.device}`}</s-text></s-box>
              <s-box padding="small"><s-text>{`${bench.status.replace('_', ' ')} · bought £${Number(bench.paid).toFixed(2)} · IMEI …${String(bench.imei).slice(-6)}`}</s-text></s-box>
              {(bench.costs || []).map((c) => (
                <s-box padding="small" key={c.row_id}>
                  <s-text>{`${c.kind === 'labour' ? '🛠 ' : ''}${c.desc} ×${c.qty} — £${(c.qty * c.cost).toFixed(2)}`}</s-text>
                  {bench.status === 'in_refurb' ? (
                    <s-button disabled={busy || undefined} onClick={() => refurb({ op: 'cost-delete', row_id: c.row_id })}>Remove</s-button>
                  ) : null}
                </s-box>
              ))}
              <s-box padding="small"><s-text>{`Refurb total £${Number(bench.refurb_total).toFixed(2)} · ALL-IN £${Number(bench.all_in).toFixed(2)}`}</s-text></s-box>

              {bench.status === 'in_stock' ? (
                <s-box padding="small">
                  <s-button disabled={busy || undefined} onClick={() => refurb({ op: 'start' }, `${bench.unit} on the bench — off the shelf`)}>
                    Start refurb (takes it off the shelf)
                  </s-button>
                </s-box>
              ) : null}

              {bench.status === 'in_refurb' ? (
                <s-box>
                  <s-box padding="small"><s-text>Add a part from stock</s-text></s-box>
                  <s-box padding="small"><s-text-field label="Search parts" value={pTerm} onChange={grab(setPTerm)} onInput={grab(setPTerm)} /></s-box>
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={() => searchParts(pTerm)}>Find parts</s-button></s-box>
                  {parts.map((p) => (
                    <s-box padding="small" key={p.id}>
                      <s-button disabled={busy || undefined} onClick={() => refurb({ op: 'cost', part_id: p.id, qty: wQty }, `${p.name} booked`)}>
                        {`${p.name}${p.compat ? ' · ' + p.compat : ''} (${p.qty} in stock)`}
                      </s-button>
                    </s-box>
                  ))}
                  <s-box padding="small"><s-text>…or a free line (part bought ad hoc / labour)</s-text></s-box>
                  <s-box padding="small"><s-text-field label="Description" value={wDesc} onChange={grab(setWDesc)} onInput={grab(setWDesc)} /></s-box>
                  <s-box padding="small"><s-button onClick={() => setWKind('part')}>{`${wKind === 'part' ? '✓ ' : ''}Part`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setWKind('labour')}>{`${wKind === 'labour' ? '✓ ' : ''}Labour`}</s-button></s-box>
                  <s-box padding="small"><s-text-field label="Qty" value={wQty} onChange={grab(setWQty)} onInput={grab(setWQty)} /></s-box>
                  <s-box padding="small"><s-text-field label="Cost each £" value={wCost} onChange={grab(setWCost)} onInput={grab(setWCost)} /></s-box>
                  <s-box padding="small">
                    <s-button disabled={busy || undefined} onClick={async () => {
                      const ok = await refurb({ op: 'cost', description: wDesc, kind: wKind, qty: wQty, unit_cost: wCost }, 'Cost line booked');
                      if (ok) { setWDesc(''); setWCost(''); setWQty('1'); }
                    }}>Add cost line</s-button>
                  </s-box>

                  <s-box padding="small"><s-text>{`Finish: new grade${nGrade ? ` — ${nGrade}` : ''}`}</s-text></s-box>
                  <s-box padding="small"><s-button onClick={() => setNGrade('Used (A - Excellent)')}>{`${nGrade === 'Used (A - Excellent)' ? '✓ ' : ''}Used (A - Excellent)`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setNGrade('Used (B - Very Good)')}>{`${nGrade === 'Used (B - Very Good)' ? '✓ ' : ''}Used (B - Very Good)`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setNGrade('Used (C - Good)')}>{`${nGrade === 'Used (C - Good)' ? '✓ ' : ''}Used (C - Good)`}</s-button></s-box>
                  <s-box padding="small"><s-text-field label="Selling price £ (blank = keep shelf price)" value={nPrice} onChange={grab(setNPrice)} onInput={grab(setNPrice)} /></s-box>
                  <s-box padding="small">
                    <s-button disabled={busy || undefined} onClick={async () => {
                      const done = await refurb({ op: 'complete', grade: nGrade, sell_price: nPrice });
                      if (done) { shopify.toast.show(`${done.unit} → ${done.grade} — back in stock. Reprint the label.`); setView('list'); loadUnits(lq); }
                    }}>✓ Complete — regrade and restock</s-button>
                  </s-box>
                  <s-box padding="small">
                    <s-button disabled={busy || undefined} onClick={async () => {
                      const done = await refurb({ op: 'cancel' });
                      if (done) { shopify.toast.show('Back on the shelf — no regrade'); setView('list'); loadUnits(lq); }
                    }}>Cancel refurb</s-button>
                  </s-box>
                </s-box>
              ) : null}
            </s-box>
          ) : view === 'list' ? (
            <s-box>
              <s-box padding="small"><s-text-field label="Search (IMEI / unit code / model)" value={lq} onChange={grab(setLq)} onInput={grab(setLq)} /></s-box>
              <s-box padding="small"><s-button disabled={busy || undefined} onClick={() => loadUnits(lq)}>{busy ? 'Searching…' : 'Search'}</s-button></s-box>
              {(units || []).map((u) => (
                <s-box padding="small" key={u.id}>
                  <s-button onClick={() => openBench(u)}>{`${u.unit} · ${u.device}`}</s-button>
                  <s-text>{`${u.status.replace('_', ' ')} · paid £${Number(u.paid).toFixed(2)} · …${u.imei4} · ${u.branch} · ${u.when}${u.seller ? ` · ${u.seller}` : ''}`}</s-text>
                </s-box>
              ))}
              {units !== null && !(units || []).length ? (
                <s-box padding="small"><s-text>No devices found.</s-text></s-box>
              ) : null}
            </s-box>
          ) : (
          <s-box>
          <s-box padding="small"><s-text-field label="IMEI / serial" value={imei} onChange={grab(setImei)} onInput={grab(setImei)} /></s-box>

          {!custom ? (
            <s-box>
              <s-box padding="small"><s-text-field label="Search model" value={search} onChange={onSearchInput} onInput={onSearchInput} /></s-box>
              <s-box>
                {models.map((m) => (
                  <s-box padding="small" key={m.product_id}>
                    <s-button onClick={() => { setProduct(m); setSel({}); }}>
                      {`${product && product.product_id === m.product_id ? '✓ ' : ''}${m.title}`}
                    </s-button>
                  </s-box>
                ))}
              </s-box>
            </s-box>
          ) : null}

          <s-box padding="small">
            <s-button onClick={() => { setCustom(!custom); setProduct(null); setSel({}); setMsg(''); }}>
              {custom ? '← Back to search' : 'Custom device'}
            </s-button>
          </s-box>

          {custom ? (
            <s-box>
              <s-box padding="small"><s-text-field label="Device name" value={cTitle} onChange={grab(setCTitle)} onInput={grab(setCTitle)} /></s-box>
              <s-box padding="small"><s-text>Grade{cGrade ? `: ${cGrade}` : ''}</s-text></s-box>
              <s-box padding="small"><s-button onClick={() => setCGrade('New')}>{`${cGrade === 'New' ? '✓ ' : ''}New`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => setCGrade('Used (A - Excellent)')}>{`${cGrade === 'Used (A - Excellent)' ? '✓ ' : ''}Used (A - Excellent)`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => setCGrade('Used (B - Very Good)')}>{`${cGrade === 'Used (B - Very Good)' ? '✓ ' : ''}Used (B - Very Good)`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => setCGrade('Used (C - Good)')}>{`${cGrade === 'Used (C - Good)' ? '✓ ' : ''}Used (C - Good)`}</s-button></s-box>
              <s-box padding="small"><s-text-field label="Selling price £" value={cSell} onChange={grab(setCSell)} onInput={grab(setCSell)} /></s-box>
            </s-box>
          ) : null}

          {(product && !custom) ? (
            <s-box>
              {Object.entries(product.options || {}).map(([optName, vals]) => (
                <s-box key={optName}>
                  <s-box padding="small"><s-text>{optName}{sel[optName] ? `: ${sel[optName]}` : ''}</s-text></s-box>
                  <s-box>
                    {vals.map((v) => (
                      <s-box padding="small" key={v}>
                        <s-button onClick={() => setSel({ ...sel, [optName]: v })}>{`${sel[optName] === v ? '✓ ' : ''}${v}`}</s-button>
                      </s-box>
                    ))}
                  </s-box>
                </s-box>
              ))}
            </s-box>
          ) : null}

          {(product || custom) ? (
            <s-box>
              <s-box padding="small"><s-text-field label={source === 'tradein' ? 'Trade-in allowance £' : 'Price paid £'} value={price} onChange={grab(setPrice)} onInput={grab(setPrice)} /></s-box>
              {!custom ? (
                <s-box padding="small"><s-text-field label="Selling price for this variant £ (current - edit to change)" value={sellPrice} onChange={grab(setSellPrice)} onInput={grab(setSellPrice)} /></s-box>
              ) : null}
              <s-box padding="small"><s-text>Source</s-text></s-box>
              <s-box padding="small"><s-button onClick={() => chooseSource('buyback')}>{`${source === 'buyback' ? '✓ ' : ''}Buy-in (Cash sale from private seller)`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => chooseSource('tradein')}>{`${source === 'tradein' ? '✓ ' : ''}Trade-in (against a sale)`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => chooseSource('supplier')}>{`${source === 'supplier' ? '✓ ' : ''}Supplier Stock`}</s-button></s-box>
              <s-box padding="small"><s-text>Paid by</s-text></s-box>
              <s-box padding="small"><s-button onClick={() => setPayout('cash')}>{`${payout === 'cash' ? '✓ ' : ''}Cash from till`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => setPayout('bank')}>{`${payout === 'bank' ? '✓ ' : ''}Bank transfer`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => setPayout('store_credit')}>{`${payout === 'store_credit' ? '✓ ' : ''}Trade-in value / store credit`}</s-button></s-box>

              {payout === 'bank' ? (
                <s-box>
                  <s-box padding="small"><s-text>Bank transfer details</s-text></s-box>
                  <s-box padding="small"><s-text-field label="Account name" value={bkName} onChange={grab(setBkName)} onInput={grab(setBkName)} /></s-box>
                  <s-box padding="small"><s-text-field label="Sort code (00-00-00)" value={bkSort} onChange={grab(setBkSort)} onInput={grab(setBkSort)} /></s-box>
                  <s-box padding="small"><s-text-field label="Account number" value={bkAcct} onChange={grab(setBkAcct)} onInput={grab(setBkAcct)} /></s-box>
                </s-box>
              ) : null}

              {source === 'supplier' ? (
                <s-box padding="small"><s-text-field label="Supplier name (optional)" value={supplier} onChange={grab(setSupplier)} onInput={grab(setSupplier)} /></s-box>
              ) : null}

              {source !== 'supplier' ? (
                <s-box>
                  <s-box padding="small"><s-text>Seller details</s-text></s-box>
                  <s-box padding="small"><s-text-field label="Full legal name" value={sName} onChange={grab(setSName)} onInput={grab(setSName)} /></s-box>
                  <s-box padding="small"><s-text-field label="Mobile" value={sMobile} onChange={grab(setSMobile)} onInput={grab(setSMobile)} /></s-box>
                  <s-box padding="small"><s-text-field label="Date of birth (YYYY-MM-DD)" value={sDob} onChange={grab(setSDob)} onInput={grab(setSDob)} /></s-box>
                  <s-box padding="small"><s-text-field label="Address" value={sAddr} onChange={grab(setSAddr)} onInput={grab(setSAddr)} /></s-box>
                  <s-box padding="small"><s-text-field label="Postcode" value={sPostcode} onChange={grab(setSPostcode)} onInput={grab(setSPostcode)} /></s-box>
                  <s-box padding="small"><s-text-field label="Email (receipt is sent here)" value={sEmail} onChange={grab(setSEmail)} onInput={grab(setSEmail)} /></s-box>
                  <s-box padding="small"><s-text>Photo ID{sIdType ? `: ${sIdType}` : ''}</s-text></s-box>
                  <s-box padding="small"><s-button onClick={() => setSIdType('Passport')}>{`${sIdType === 'Passport' ? '✓ ' : ''}Passport`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setSIdType('Driving licence')}>{`${sIdType === 'Driving licence' ? '✓ ' : ''}Driving licence`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setSIdType('BRP')}>{`${sIdType === 'BRP' ? '✓ ' : ''}BRP`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setSIdType('Other')}>{`${sIdType === 'Other' ? '✓ ' : ''}Other`}</s-button></s-box>
                  <s-box padding="small"><s-text-field label="ID ref / expiry" value={sIdRef} onChange={grab(setSIdRef)} onInput={grab(setSIdRef)} /></s-box>
                </s-box>
              ) : null}

              <s-box padding="small"><s-text-field label="Battery % (optional)" value={battery} onChange={grab(setBattery)} onInput={grab(setBattery)} /></s-box>
              <s-box padding="small"><s-button disabled={busy || undefined} onClick={submit}>{busy ? 'Saving…' : 'Save intake (+1 stock)'}</s-button></s-box>
            </s-box>
          ) : null}
          </s-box>
          )}
        </s-box>
      </s-scroll-box>
    </s-page>
  );
}
