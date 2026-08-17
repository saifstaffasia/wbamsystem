import {render} from 'preact';
import {useState, useRef} from 'preact/hooks';

// Interim host (WP Engine) — switch to https://system.webuyanymobile.com and redeploy.
const HUB = 'https://wbamsystem.wpenginepowered.com';

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
  const [source, setSource] = useState('buyback');
  const [payout, setPayout] = useState('cash');
  const [battery, setBattery] = useState('');

  // bank payout
  const [bkName, setBkName] = useState('');
  const [bkSort, setBkSort] = useState('');
  const [bkAcct, setBkAcct] = useState('');

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
    const res = await fetch(`${HUB}/wp-json/wbam/v1/pos/${path}`, {
      ...opts,
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(opts.headers || {}) },
    });
    const json = await res.json();
    if (!res.ok) throw new Error((json && json.message) || `HTTP ${res.status}`);
    return json;
  };

  const doSearch = async (term) => {
    if (!term || term.trim().length < 2) return;
    try { setModels(await call(`models?term=${encodeURIComponent(term.trim())}`)); } catch (e) {}
  };
  const onSearchInput = (e) => {
    const v = e && e.currentTarget ? e.currentTarget.value : '';
    setSearch(v);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => doSearch(v), 400);
  };
  const chooseSource = (s) => { setSource(s); if (s === 'tradein') setPayout('store_credit'); };
  const optionsComplete = () => product && Object.keys(product.options || {}).every((n) => sel[n]);

  const submit = async () => {
    if (custom) {
      if (!cTitle.trim()) { setMsg('Enter the device name.'); return; }
      if (!(parseFloat(cSell) > 0)) { setMsg('Enter the selling price.'); return; }
    } else {
      if (!product) { setMsg('Pick the model (or use Custom device).'); return; }
      if (!optionsComplete()) { setMsg('Pick every option.'); return; }
    }
    if (price === '' || !(parseFloat(price) >= 0)) { setMsg('Enter the price paid.'); return; }
    if (source !== 'supplier' && (!sName.trim() || !sMobile.trim())) { setMsg('Seller name and mobile are required.'); return; }
    if (payout === 'bank' && (!bkName.trim() || !bkSort.trim() || !bkAcct.trim())) { setMsg('Bank details incomplete (account name, sort code, account number).'); return; }
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
          email: sEmail, id_type: sIdType, id_ref: sIdRef,
        },
      };
      if (payout === 'bank') body.bank = { account_name: bkName, sort_code: bkSort, account_number: bkAcct };
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
        shopify.toast.show(`${res.unit_code} saved. At payment: tap "Trade In", enter £${allowance}, Accept — the rest by card/cash.`);
      } else {
        shopify.toast.show(`${res.unit_code} in stock — label & declaration print from the Hub`);
      }
      setImei(''); setSearch(''); setModels([]); setProduct(null); setSel({});
      setCustom(false); setCTitle(''); setCGrade('Used (B - Very Good)'); setCSell('');
      setPrice(''); setSource('buyback'); setPayout('cash'); setBattery('');
      setBkName(''); setBkSort(''); setBkAcct('');
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
          {msg ? <s-box padding="small"><s-text>⚠ {msg}</s-text></s-box> : null}

          <s-box padding="small"><s-text-field label="IMEI / serial (scan — dial *#06#)" value={imei} onChange={grab(setImei)} onInput={grab(setImei)} /></s-box>

          {!custom ? (
            <s-box>
              <s-box padding="small"><s-text-field label="Model search — results appear as you type" value={search} onChange={onSearchInput} onInput={onSearchInput} /></s-box>
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
              {custom ? '← Back to catalog search' : '➕ Not in the list? Custom device'}
            </s-button>
          </s-box>

          {custom ? (
            <s-box>
              <s-box padding="small"><s-text-field label="Device name (e.g. Google Pixel 8 128GB Black)" value={cTitle} onChange={grab(setCTitle)} onInput={grab(setCTitle)} /></s-box>
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
              <s-box padding="small"><s-text>Source</s-text></s-box>
              <s-box padding="small"><s-button onClick={() => chooseSource('buyback')}>{`${source === 'buyback' ? '✓ ' : ''}Buy-in (walk-in seller)`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => chooseSource('tradein')}>{`${source === 'tradein' ? '✓ ' : ''}Trade-in (against this sale)`}</s-button></s-box>
              <s-box padding="small"><s-button onClick={() => chooseSource('supplier')}>{`${source === 'supplier' ? '✓ ' : ''}Supplier stock`}</s-button></s-box>
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

              {source !== 'supplier' ? (
                <s-box>
                  <s-box padding="small"><s-text>Seller details (for the declaration)</s-text></s-box>
                  <s-box padding="small"><s-text-field label="Full legal name" value={sName} onChange={grab(setSName)} onInput={grab(setSName)} /></s-box>
                  <s-box padding="small"><s-text-field label="Mobile" value={sMobile} onChange={grab(setSMobile)} onInput={grab(setSMobile)} /></s-box>
                  <s-box padding="small"><s-text-field label="Date of birth (YYYY-MM-DD)" value={sDob} onChange={grab(setSDob)} onInput={grab(setSDob)} /></s-box>
                  <s-box padding="small"><s-text-field label="Address" value={sAddr} onChange={grab(setSAddr)} onInput={grab(setSAddr)} /></s-box>
                  <s-box padding="small"><s-text-field label="Postcode" value={sPostcode} onChange={grab(setSPostcode)} onInput={grab(setSPostcode)} /></s-box>
                  <s-box padding="small"><s-text-field label="Email (optional)" value={sEmail} onChange={grab(setSEmail)} onInput={grab(setSEmail)} /></s-box>
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
      </s-scroll-box>
    </s-page>
  );
}
