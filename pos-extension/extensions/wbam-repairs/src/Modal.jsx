import {render} from 'preact';
import {useState} from 'preact/hooks';

// Interim host (WP Engine) — switch to https://system.webuyanymobile.com and redeploy.
const HUB = 'https://system.webuyanymobile.com';

export default async () => {
  render(<Extension />, document.body);
};

/**
 * Repairs at the till: book a repair (customer gets confirmation automatically,
 * optional deposit straight into the basket) and take deposits/balances against
 * existing tickets — the SERVER composes cart-line titles, staff never type codes.
 * Device intake lives on its own tile ("Trade In Device Intake").
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

  // payment fields
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [picked, setPicked] = useState(null);
  const [amount, setAmount] = useState('');

  const pickType = (r) => () => setRtype(rtype === r ? '' : r);
  const tlabel = (r) => (rtype === r ? '✓ ' : '') + r;
  const grab = (setter) => (e) => setter(e && e.currentTarget ? e.currentTarget.value : '');

  const call = async (path, opts = {}) => {
    const token = await shopify.session.getSessionToken();
    const res = await fetch(`${HUB}/wp-json/wbam/v1/pos/${path}`, {
      ...opts,
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(opts.headers || {}) },
    });
    const json = await res.json();
    if (!res.ok) throw new Error((json && json.message) || `HTTP ${res.status}`);
    return json;
  };

  const addLine = async (ticketId, which, amt) => {
    const line = await call('line', {
      method: 'POST',
      body: JSON.stringify({ ticket_id: ticketId, which, amount: amt }),
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

  return (
    <s-page heading="Repairs">
      <s-scroll-box>
        <s-box padding="small">
          {msg ? (
            <s-box padding="small">
              <s-text>{msg}</s-text>
            </s-box>
          ) : null}

          {view === 'home' && (
            <s-box>
              <s-box padding="small">
                <s-button onClick={() => { setMsg(''); setView('book'); }}>New repair booking</s-button>
              </s-box>
              <s-box padding="small">
                <s-button onClick={() => { setMsg(''); setView('pay'); }}>Take deposit / balance</s-button>
              </s-box>
            </s-box>
          )}

          {view === 'book' && (
            <s-box>
              <s-box padding="small"><s-text-field label="Customer name" value={name} onChange={grab(setName)} onInput={grab(setName)} /></s-box>
              <s-box padding="small"><s-text-field label="Phone" value={phone} onChange={grab(setPhone)} onInput={grab(setPhone)} /></s-box>
              <s-box padding="small"><s-text-field label="Email (optional)" value={email} onChange={grab(setEmail)} onInput={grab(setEmail)} /></s-box>
              <s-box padding="small"><s-text-field label="Device" value={device} onChange={grab(setDevice)} onInput={grab(setDevice)} /></s-box>
              <s-box padding="small"><s-text-field label="IMEI (optional)" value={imei} onChange={grab(setImei)} onInput={grab(setImei)} /></s-box>
              <s-box padding="small"><s-text-field label="Passcode" value={passcode} onChange={grab(setPasscode)} onInput={grab(setPasscode)} /></s-box>
              <s-box padding="small"><s-text-field label="Fault" value={fault} onChange={grab(setFault)} onInput={grab(setFault)} /></s-box>

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
                <s-button onClick={() => setHeld(false)}>{`${!held ? '✓ ' : ''}No — device comes later`}</s-button>
              </s-box>

              <s-box padding="small"><s-text-field label="Estimated completion (YYYY-MM-DD)" value={due} onChange={grab(setDue)} onInput={grab(setDue)} /></s-box>
              <s-box padding="small"><s-text-field label="Quote £ (optional)" value={quote} onChange={grab(setQuote)} onInput={grab(setQuote)} /></s-box>
              <s-box padding="small"><s-text-field label="Deposit £ (optional)" value={deposit} onChange={grab(setDeposit)} onInput={grab(setDeposit)} /></s-box>
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
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={() => takePayment('deposit')}>Add deposit to cart</s-button></s-box>
                  <s-box padding="small"><s-button disabled={busy || undefined} onClick={() => takePayment('balance')}>Add balance to cart</s-button></s-box>
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
