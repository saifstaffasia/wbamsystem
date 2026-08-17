import {render} from 'preact';
import {useState, useRef} from 'preact/hooks';

// Interim host (WP Engine) — switch to https://system.webuyanymobile.com and
// re-run `shopify app deploy` when the real domain is connected.
const HUB = 'https://wbamsystem.wpenginepowered.com';

export default async () => {
  render(<Extension />, document.body);
};

/**
 * One modal, three flows, zero typing of ticket codes:
 *  - New booking: creates the ticket in the Hub (customer gets email/SMS),
 *    optionally drops the deposit straight into the POS cart.
 *  - Take payment: search open tickets, tap one, deposit/balance → cart line
 *    whose title is composed by the SERVER ("Repair deposit — T-0042 (iPhone 12)").
 */
function Extension() {
  const [view, setView] = useState('home'); // home | book | pay
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');

  // booking fields
  const defaultDue = () => new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [device, setDevice] = useState('');
  const [imei, setImei] = useState('');
  const [passcode, setPasscode] = useState('');
  const [fault, setFault] = useState('');
  const [rtype, setRtype] = useState('');
  const [held, setHeld] = useState(true);
  const [due, setDue] = useState(defaultDue());
  const [quote, setQuote] = useState('');
  const [deposit, setDeposit] = useState('');

  const pickType = (r) => () => setRtype(rtype === r ? '' : r);
  const tlabel = (r) => (rtype === r ? '✓ ' : '') + r;

  // payment fields
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [picked, setPicked] = useState(null);
  const [amount, setAmount] = useState('');

  // intake (buy-in) fields
  const [iImei, setIImei] = useState('');
  const [iSearch, setISearch] = useState('');
  const [iModels, setIModels] = useState([]);
  const [iProduct, setIProduct] = useState(null);
  const [iSel, setISel] = useState({});
  const [iPrice, setIPrice] = useState('');
  const [iSource, setISource] = useState('buyback');
  const [iPayout, setIPayout] = useState('cash');
  const [iBattery, setIBattery] = useState('');
  const [iRef, setIRef] = useState('');

  // custom-device intake (not in catalog)
  const [iCustom, setICustom] = useState(false);
  const [cTitle, setCTitle] = useState('');
  const [cGrade, setCGrade] = useState('Used (B - Very Good)');
  const [cSell, setCSell] = useState('');
  const searchTimer = useRef(null);

  const call = async (path, opts = {}) => {
    const token = await shopify.session.getSessionToken();
    const res = await fetch(`${HUB}/wp-json/wbam/v1/pos/${path}`, {
      ...opts,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        ...(opts.headers || {}),
      },
    });
    const json = await res.json();
    if (!res.ok) throw new Error((json && json.message) || `HTTP ${res.status}`);
    return json;
  };

  const addLine = async (ticketId, which, amt) => {
    const line = await call('line', {
      method: 'POST',
      body: JSON.stringify({ticket_id: ticketId, which, amount: amt}),
    });
    await shopify.cart.addCustomSale({
      title: line.title,
      price: line.price,
      quantity: 1,
      taxable: !!line.taxable,
    });
  };

  const submitBooking = async () => {
    setBusy(true);
    setMsg('');
    try {
      const t = await call('booking', {
        method: 'POST',
        body: JSON.stringify({
          customer_name: name,
          phone,
          email,
          device_model: device,
          imei,
          passcode,
          fault,
          repair_type: rtype,
          due_date: due,
          device_held: held ? 1 : 0,
          quote,
          location_id: shopify.session.currentSession.locationId,
        }),
      });
      const dep = parseFloat(deposit || '0');
      if (dep > 0) {
        await addLine(t.id, 'deposit', dep);
        shopify.toast.show(`${t.ticket} booked — deposit £${dep.toFixed(2)} in cart`);
      } else {
        shopify.toast.show(`${t.ticket} booked`);
      }
      setName(''); setPhone(''); setEmail(''); setDevice(''); setImei(''); setPasscode(''); setFault('');
      setRtype(''); setHeld(true); setDue(defaultDue()); setQuote(''); setDeposit('');
      setView('home');
    } catch (e) {
      setMsg(String((e && e.message) || e));
    }
    setBusy(false);
  };

  const search = async () => {
    setBusy(true);
    setMsg('');
    setPicked(null);
    try {
      setResults(await call(`tickets?q=${encodeURIComponent(q)}`));
    } catch (e) {
      setMsg(String((e && e.message) || e));
    }
    setBusy(false);
  };

  const takePayment = async (which) => {
    if (!picked) return;
    const amt = parseFloat(amount || (picked.due != null ? String(picked.due) : '0'));
    if (!(amt > 0)) { setMsg('Enter an amount.'); return; }
    setBusy(true);
    setMsg('');
    try {
      await addLine(picked.id, which, amt);
      shopify.toast.show(`${picked.ticket} ${which} £${amt.toFixed(2)} in cart`);
      setView('home'); setQ(''); setResults([]); setPicked(null); setAmount('');
    } catch (e) {
      setMsg(String((e && e.message) || e));
    }
    setBusy(false);
  };

  const grab = (setter) => (e) => setter(e && e.currentTarget ? e.currentTarget.value : '');

  /* ---------------- intake (buy-in) ---------------- */

  const doSearch = async (term) => {
    if (!term || term.trim().length < 2) return;
    try {
      setIModels(await call(`models?term=${encodeURIComponent(term.trim())}`));
    } catch (e) { /* silent for live search */ }
  };

  // Live search: results populate ~0.4s after the staff member stops typing.
  const onSearchInput = (e) => {
    const v = e && e.currentTarget ? e.currentTarget.value : '';
    setISearch(v);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => doSearch(v), 400);
  };

  const searchModels = async () => {
    setBusy(true);
    setMsg('');
    await doSearch(iSearch);
    setBusy(false);
  };

  const chooseSource = (s) => {
    setISource(s);
    if (s === 'tradein') setIPayout('store_credit');
  };

  const optionsComplete = () =>
    iProduct && Object.keys(iProduct.options || {}).every((n) => iSel[n]);

  const submitIntake = async () => {
    if (iCustom) {
      if (!cTitle.trim()) { setMsg('Enter the device name.'); return; }
      if (!(parseFloat(cSell) > 0)) { setMsg('Enter the selling price.'); return; }
    } else {
      if (!iProduct) { setMsg('Pick the model first.'); return; }
      if (!optionsComplete()) { setMsg('Pick every option (colour / storage / condition).'); return; }
    }
    if (!(parseFloat(iPrice) >= 0) || iPrice === '') { setMsg('Enter the price paid.'); return; }
    setBusy(true);
    setMsg('');
    try {
      const body = {
        imei: iImei,
        purchase_price: parseFloat(iPrice),
        source: iSource,
        source_ref: iRef,
        payout_method: iPayout,
        battery_health: iBattery,
        location_id: shopify.session.currentSession.locationId,
      };
      if (iCustom) {
        body.custom = 1;
        body.title = cTitle.trim();
        body.grade = cGrade;
        body.sell_price = parseFloat(cSell);
      } else {
        body.product_id = iProduct.product_id;
        body.model_title = iProduct.title;
        body.selected = iSel;
      }
      const res = await call('intake', { method: 'POST', body: JSON.stringify(body) });

      if (iSource === 'tradein') {
        const allowance = parseFloat(iPrice).toFixed(2);
        // Marker 1: cart attribute (reliable) — the Hub links unit ↔ order from it.
        try {
          await shopify.cart.addCartProperties({ [`Trade-in ${res.unit_code}`]: `${res.title} — allowance £${allowance}` });
        } catch (e) { /* older POS builds may lack this */ }
        // Marker 2: £0 receipt line (best effort — some builds refuse £0 custom sales).
        try {
          await shopify.cart.addCustomSale({
            title: `Trade-in — ${res.unit_code} (${res.title}) — allowance £${allowance}`,
            price: '0.00',
            quantity: 1,
            taxable: false,
          });
        } catch (e) {}
        shopify.toast.show(`${res.unit_code} saved. At payment: tap "Trade In", enter £${allowance}, Accept — POS then asks for the rest by card/cash.`);
      } else {
        shopify.toast.show(`${res.unit_code} in stock — print its label from Hub → Units`);
      }
      setIImei(''); setISearch(''); setIModels([]); setIProduct(null); setISel({});
      setIPrice(''); setISource('buyback'); setIPayout('cash'); setIBattery(''); setIRef('');
      setICustom(false); setCTitle(''); setCGrade('Used (B - Very Good)'); setCSell('');
      setView('home');
    } catch (e) {
      setMsg(String((e && e.message) || e));
    }
    setBusy(false);
  };

  return (
    <s-page heading="Repairs">
      <s-scroll-box>
        <s-box padding="small">
          {msg ? (
            <s-box padding="small">
              <s-text>⚠ {msg}</s-text>
            </s-box>
          ) : null}

          {view === 'home' && (
            <s-box>
              <s-box padding="small">
                <s-button onClick={() => { setMsg(''); setView('book'); }}>➕ New repair booking</s-button>
              </s-box>
              <s-box padding="small">
                <s-button onClick={() => { setMsg(''); setView('pay'); }}>💳 Take deposit / balance</s-button>
              </s-box>
              <s-box padding="small">
                <s-button onClick={() => { setMsg(''); setView('intake'); }}>📥 Intake device (buy-in)</s-button>
              </s-box>
              <s-box padding="small">
                <s-text>Bookings confirm to the customer automatically. Payments are added to the current cart — check out as normal (cash, card or Trade In). Intake registers the IMEI, adds shelf stock and logs the payout.</s-text>
              </s-box>
            </s-box>
          )}

          {view === 'book' && (
            <s-box>
              <s-box padding="small"><s-text-field label="Customer name" value={name} onChange={grab(setName)} onInput={grab(setName)} /></s-box>
              <s-box padding="small"><s-text-field label="Phone" value={phone} onChange={grab(setPhone)} onInput={grab(setPhone)} /></s-box>
              <s-box padding="small"><s-text-field label="Email (optional)" value={email} onChange={grab(setEmail)} onInput={grab(setEmail)} /></s-box>
              <s-box padding="small"><s-text-field label="Device (e.g. iPhone 12)" value={device} onChange={grab(setDevice)} onInput={grab(setDevice)} /></s-box>
              <s-box padding="small"><s-text-field label="IMEI (optional — dial *#06#)" value={imei} onChange={grab(setImei)} onInput={grab(setImei)} /></s-box>
              <s-box padding="small"><s-text-field label="Passcode (for testing the device)" value={passcode} onChange={grab(setPasscode)} onInput={grab(setPasscode)} /></s-box>
              <s-box padding="small"><s-text-field label="What's wrong?" value={fault} onChange={grab(setFault)} onInput={grab(setFault)} /></s-box>

              <s-box padding="small"><s-text>Repair type{rtype ? `: ${rtype}` : ''}</s-text></s-box>
              <s-box padding="small"><s-button onClick={pickType('Diagnosis')}>{tlabel('Diagnosis')}</s-button></s-box>
              <s-box padding="small"><s-button onClick={pickType('Screen Change')}>{tlabel('Screen Change')}</s-button></s-box>
              <s-box padding="small"><s-button onClick={pickType('Battery Change')}>{tlabel('Battery Change')}</s-button></s-box>
              <s-box padding="small"><s-button onClick={pickType('Backglass Change')}>{tlabel('Backglass Change')}</s-button></s-box>
              <s-box padding="small"><s-button onClick={pickType('Other Repair')}>{tlabel('Other Repair')}</s-button></s-box>

              <s-box padding="small"><s-text>Device staying with us?</s-text></s-box>
              <s-box padding="small">
                <s-button onClick={() => setHeld(true)}>{`${held ? '✓ ' : ''}Yes — device is here`}</s-button>
              </s-box>
              <s-box padding="small">
                <s-button onClick={() => setHeld(false)}>{`${!held ? '✓ ' : ''}No — deposit now, device comes later`}</s-button>
              </s-box>

              <s-box padding="small"><s-text-field label="Estimated completion (YYYY-MM-DD)" value={due} onChange={grab(setDue)} onInput={grab(setDue)} /></s-box>
              <s-box padding="small"><s-text-field label="Quote £ (optional)" value={quote} onChange={grab(setQuote)} onInput={grab(setQuote)} /></s-box>
              <s-box padding="small"><s-text-field label="Deposit to take now £ (optional)" value={deposit} onChange={grab(setDeposit)} onInput={grab(setDeposit)} /></s-box>
              <s-box padding="small"><s-button disabled={busy || undefined} onClick={submitBooking}>{busy ? 'Booking…' : 'Book repair'}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => setView('home')}>← Back</s-button></s-box>
            </s-box>
          )}

          {view === 'pay' && (
            <s-box>
              <s-box padding="small"><s-text-field label="Find ticket (number, name or phone)" value={q} onChange={grab(setQ)} onInput={grab(setQ)} /></s-box>
              <s-box padding="small"><s-button disabled={busy || undefined} onClick={search}>{busy ? 'Searching…' : 'Search'}</s-button></s-box>
              <s-box>
                {results.map((t) => (
                  <s-box padding="small" key={t.id}>
                    <s-button onClick={() => { setPicked(t); setAmount(t.due != null ? String(t.due) : ''); }}>
                      {`${t.ticket} · ${t.customer} · ${t.device}${t.due != null ? ` · due £${Number(t.due).toFixed(2)}` : ''}${picked && picked.id === t.id ? ' ✓' : ''}`}
                    </s-button>
                  </s-box>
                ))}
              </s-box>
              {picked ? (
                <s-box>
                  <s-box padding="small">
                    <s-text>{`${picked.ticket} — ${picked.customer} (${picked.status}). Paid so far £${Number(picked.paid).toFixed(2)}.`}</s-text>
                  </s-box>
                  <s-box padding="small"><s-text-field label="Amount £" value={amount} onChange={grab(setAmount)} onInput={grab(setAmount)} /></s-box>
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={() => takePayment('deposit')}>Add DEPOSIT to cart</s-button></s-box>
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={() => takePayment('balance')}>Add BALANCE to cart</s-button></s-box>
                </s-box>
              ) : null}
              <s-box padding="small"><s-button onClick={() => setView('home')}>← Back</s-button></s-box>
            </s-box>
          )}

          {view === 'intake' && (
            <s-box>
              <s-box padding="small"><s-text-field label="IMEI / serial (scan here — dial *#06#)" value={iImei} onChange={grab(setIImei)} onInput={grab(setIImei)} /></s-box>
              {!iCustom ? (
                <s-box>
                  <s-box padding="small"><s-text-field label="Model search — results appear as you type" value={iSearch} onChange={onSearchInput} onInput={onSearchInput} /></s-box>
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={searchModels}>{busy ? 'Searching…' : 'Find model'}</s-button></s-box>
                  <s-box>
                    {iModels.map((m) => (
                      <s-box padding="small" key={m.product_id}>
                        <s-button onClick={() => { setIProduct(m); setISel({}); }}>
                          {`${iProduct && iProduct.product_id === m.product_id ? '✓ ' : ''}${m.title}`}
                        </s-button>
                      </s-box>
                    ))}
                  </s-box>
                </s-box>
              ) : null}
              <s-box padding="small">
                <s-button onClick={() => { setICustom(!iCustom); setIProduct(null); setISel({}); setMsg(''); }}>
                  {iCustom ? '← Back to catalog search' : '➕ Not in the list? Custom device'}
                </s-button>
              </s-box>
              {iCustom ? (
                <s-box>
                  <s-box padding="small"><s-text-field label="Device name (e.g. Google Pixel 8 128GB Black)" value={cTitle} onChange={grab(setCTitle)} onInput={grab(setCTitle)} /></s-box>
                  <s-box padding="small"><s-text>Grade{cGrade ? `: ${cGrade}` : ''}</s-text></s-box>
                  <s-box padding="small"><s-button onClick={() => setCGrade('New')}>{`${cGrade === 'New' ? '✓ ' : ''}New`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setCGrade('Used (A - Excellent)')}>{`${cGrade === 'Used (A - Excellent)' ? '✓ ' : ''}Used (A - Excellent)`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setCGrade('Used (B - Very Good)')}>{`${cGrade === 'Used (B - Very Good)' ? '✓ ' : ''}Used (B - Very Good)`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setCGrade('Used (C - Good)')}>{`${cGrade === 'Used (C - Good)' ? '✓ ' : ''}Used (C - Good)`}</s-button></s-box>
                  <s-box padding="small"><s-text-field label="Selling price £ (what it'll be listed at)" value={cSell} onChange={grab(setCSell)} onInput={grab(setCSell)} /></s-box>
                </s-box>
              ) : null}
              {(iProduct && !iCustom) ? (
                <s-box>
                  {Object.entries(iProduct.options || {}).map(([optName, vals]) => (
                    <s-box key={optName}>
                      <s-box padding="small"><s-text>{optName}{iSel[optName] ? `: ${iSel[optName]}` : ''}</s-text></s-box>
                      <s-box>
                        {vals.map((v) => (
                          <s-box padding="small" key={v}>
                            <s-button onClick={() => setISel({ ...iSel, [optName]: v })}>{`${iSel[optName] === v ? '✓ ' : ''}${v}`}</s-button>
                          </s-box>
                        ))}
                      </s-box>
                    </s-box>
                  ))}
                </s-box>
              ) : null}
              {(iProduct || iCustom) ? (
                <s-box>
                  <s-box padding="small"><s-text-field label={iSource === 'tradein' ? 'Trade-in allowance £ (what you give them)' : 'Price paid £'} value={iPrice} onChange={grab(setIPrice)} onInput={grab(setIPrice)} /></s-box>
                  <s-box padding="small"><s-text>Source</s-text></s-box>
                  <s-box padding="small"><s-button onClick={() => chooseSource('buyback')}>{`${iSource === 'buyback' ? '✓ ' : ''}Buy-in (walk-in seller)`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => chooseSource('tradein')}>{`${iSource === 'tradein' ? '✓ ' : ''}Trade-in (against this sale)`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => chooseSource('supplier')}>{`${iSource === 'supplier' ? '✓ ' : ''}Supplier stock`}</s-button></s-box>
                  <s-box padding="small"><s-text>Paid by</s-text></s-box>
                  <s-box padding="small"><s-button onClick={() => setIPayout('cash')}>{`${iPayout === 'cash' ? '✓ ' : ''}Cash from till`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setIPayout('bank')}>{`${iPayout === 'bank' ? '✓ ' : ''}Bank transfer`}</s-button></s-box>
                  <s-box padding="small"><s-button onClick={() => setIPayout('store_credit')}>{`${iPayout === 'store_credit' ? '✓ ' : ''}Trade-in value / store credit`}</s-button></s-box>
                  <s-box padding="small"><s-text-field label="Battery % (optional)" value={iBattery} onChange={grab(setIBattery)} onInput={grab(setIBattery)} /></s-box>
                  <s-box padding="small"><s-text-field label="Seller name / ref (optional)" value={iRef} onChange={grab(setIRef)} onInput={grab(setIRef)} /></s-box>
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={submitIntake}>{busy ? 'Saving…' : 'Save intake (+1 stock)'}</s-button></s-box>
                </s-box>
              ) : null}
              <s-box padding="small"><s-button onClick={() => setView('home')}>← Back</s-button></s-box>
            </s-box>
          )}
        </s-box>
      </s-scroll-box>
    </s-page>
  );
}
