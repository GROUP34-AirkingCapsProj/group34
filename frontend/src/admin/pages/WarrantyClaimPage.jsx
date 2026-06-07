import React, { useState, useRef, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import { warrantyClaimsAPI, customersAPI, locationsAPI, getApiBaseUrl, getToken } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/warranty_claim.css';

const STEP = { FORM: 1, ITEMS: 2, DONE: 3 };

export default function WarrantyClaimPage() {
  const [step, setStep] = useState(STEP.FORM);
  const [form, setForm] = useState({
    customer_id: '', customer_label: '', serial_number: '',
    claim_type: 'repair', notes: '', location_id: '',
  });
  const [customerResults, setCustomerResults] = useState([]);
  const [locations, setLocations] = useState([]);
  const [claim, setClaim] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [scanValue, setScanValue] = useState('');
  const [qty, setQty] = useState(1);
  const scanRef = useRef(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const s = params.get('serial');
    if (s) setForm((f) => ({ ...f, serial_number: s }));

    (async () => {
      try {
        const res = await locationsAPI.getAll();
        const list = Array.isArray(res?.data) ? res.data : [];
        setLocations(list);
        // Auto-select when there is exactly one choice.
        if (list.length === 1) {
          const only = list[0];
          setForm((f) => ({ ...f, location_id: String(only.location_id ?? only.id) }));
        }
      } catch { setLocations([]); }
    })();
  }, []);

  const searchCustomers = async (q) => {
    setForm((f) => ({ ...f, customer_label: q, customer_id: '' }));
    if (q.trim().length < 2) { setCustomerResults([]); return; }
    try {
      const res = await customersAPI.search(q);
      setCustomerResults(Array.isArray(res?.data) ? res.data : []);
    } catch { setCustomerResults([]); }
  };

  const pickCustomer = (c) => {
    setForm((f) => ({ ...f, customer_id: c.customer_id, customer_label: c.customer_name }));
    setCustomerResults([]);
  };

  const startClaim = async (e) => {
    e.preventDefault();
    setError('');
    if (!form.customer_id) { setError('Select a customer.'); return; }
    if (!form.serial_number.trim()) { setError('Enter or scan the serial number being claimed.'); return; }
    if (!form.location_id) { setError('Select a location.'); return; }
    setBusy(true);
    try {
      const res = await warrantyClaimsAPI.init({
        customer_id: form.customer_id,
        serial_number: form.serial_number.trim(),
        claim_type: form.claim_type,
        notes: form.notes || null,
        location_id: form.location_id || undefined,
      });
      setClaim(res?.data ?? res);
      setStep(STEP.ITEMS);
    } catch (err) {
      setError(err.message || 'Could not start the claim.');
    } finally { setBusy(false); }
  };

  const addItem = async () => {
    if (!scanValue.trim()) return;
    setBusy(true); setError('');
    try {
      const res = await warrantyClaimsAPI.addItem(claim.claim_id, {
        barcode: scanValue.trim(),
        quantity: form.claim_type === 'repair' ? Number(qty) || 1 : undefined,
      });
      setClaim(res?.data ?? res);
      setScanValue(''); setQty(1);
      scanRef.current?.focus();
    } catch (err) {
      setError(err.message || 'Could not add the item.');
    } finally { setBusy(false); }
  };

  const removeItem = async (detailId) => {
    setBusy(true);
    try {
      const res = await warrantyClaimsAPI.removeItem(claim.claim_id, detailId);
      setClaim(res?.data ?? res);
    } catch (err) { toast.error(err.message || 'Could not remove item.'); }
    finally { setBusy(false); }
  };

  const complete = async () => {
    setBusy(true); setError('');
    try {
      const res = await warrantyClaimsAPI.complete(claim.claim_id);
      setClaim(res?.data ?? res);
      setStep(STEP.DONE);
      toast.success('Warranty claim completed.');
    } catch (err) {
      setError(err.message || 'Could not complete the claim.');
    } finally { setBusy(false); }
  };

  const downloadSlip = () => {
    const url = `${getApiBaseUrl()}${warrantyClaimsAPI.slipUrl(claim.claim_id)}`;
    fetch(url, { headers: { Authorization: `Bearer ${getToken()}` } })
      .then((r) => r.blob())
      .then((b) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = `${claim.claim_number}.csv`;
        a.click();
      });
  };

  const items = claim?.items ?? [];

  return (
    <AdminLayout>
      <div className="wc-wrap">
        <h1>Warranty Claim</h1>
        <div className="wc-steps">
          <div className={`wc-step ${step === STEP.FORM ? 'active' : 'done'}`}>1. Claim Details</div>
          <div className={`wc-step ${step === STEP.ITEMS ? 'active' : step > STEP.ITEMS ? 'done' : ''}`}>2. Items</div>
          <div className={`wc-step ${step === STEP.DONE ? 'active' : ''}`}>3. Done</div>
        </div>

        {step === STEP.FORM && (
          <form className="wc-card" onSubmit={startClaim}>
            <div className="wc-form-group" style={{ position: 'relative' }}>
              <label>Customer *</label>
              <input value={form.customer_label} onChange={(e) => searchCustomers(e.target.value)} placeholder="Search customer…" />
              {customerResults.length > 0 && (
                <div className="wc-card" style={{ position: 'absolute', top: '100%', zIndex: 5, width: '100%' }}>
                  {customerResults.map((c) => (
                    <div key={c.customer_id} className="wc-item-row" style={{ cursor: 'pointer' }} onClick={() => pickCustomer(c)}>
                      {c.customer_name} <small>{c.customer_code}</small>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="wc-form-group">
              <label>Serial Number (scan or type) *</label>
              <input value={form.serial_number} onChange={(e) => setForm((f) => ({ ...f, serial_number: e.target.value }))} placeholder="Scan or type the claimed unit serial" />
            </div>
            <div className="wc-row">
              <div className="wc-form-group">
                <label>Claim Type *</label>
                <select value={form.claim_type} onChange={(e) => setForm((f) => ({ ...f, claim_type: e.target.value }))}>
                  <option value="repair">Repair (use spare parts)</option>
                  <option value="replacement">Replacement (give a new unit)</option>
                </select>
              </div>
              <div className="wc-form-group">
                <label>Location *</label>
                <select value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value }))}>
                  <option value="">Select location…</option>
                  {locations.map((l) => {
                    const id = l.location_id ?? l.id;
                    return <option key={id} value={String(id)}>{l.location_name || l.name}</option>;
                  })}
                </select>
              </div>
            </div>
            <div className="wc-form-group">
              <label>Notes / Problem</label>
              <textarea rows={2} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} />
            </div>
            {error && <div className="wc-error">{error}</div>}
            <button className="wc-btn-primary" disabled={busy}>{busy ? 'Validating…' : 'Start Claim'}</button>
          </form>
        )}

        {step === STEP.ITEMS && claim && (
          <>
            <div className="wc-card">
              <div className="wc-banner">
                Claim {claim.claim_number} · {claim.claim_type} · serial {claim.serial_number}
                {claim.claim_type === 'replacement' ? ' · scan ONE replacement unit serial' : ' · scan consumable parts'}
              </div>
              <div className="wc-row" style={{ marginTop: '.8rem' }}>
                <div className="wc-form-group" style={{ flex: 3 }}>
                  <label>{claim.claim_type === 'replacement' ? 'Replacement unit serial' : 'Part barcode'}</label>
                  <input ref={scanRef} value={scanValue} autoFocus
                    onChange={(e) => setScanValue(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addItem(); } }} />
                </div>
                {claim.claim_type === 'repair' && (
                  <div className="wc-form-group">
                    <label>Qty</label>
                    <input type="number" min="1" value={qty} onChange={(e) => setQty(e.target.value)} />
                  </div>
                )}
              </div>
              <button className="wc-btn-secondary" onClick={addItem} disabled={busy || !scanValue.trim()}>Add</button>
              {error && <div className="wc-error" style={{ marginTop: '.5rem' }}>{error}</div>}
            </div>

            <div className="wc-card">
              <h3>Items ({items.length})</h3>
              {items.length === 0 && <p>No items yet.</p>}
              {items.map((it) => (
                <div key={it.detail_id} className="wc-item-row">
                  <span>{it.product_name} {it.serial_number ? `· ${it.serial_number}` : `· ${it.quantity_issued} ${it.quantity_unit || ''}`}</span>
                  <button className="wc-btn-secondary" onClick={() => removeItem(it.detail_id)} disabled={busy}>Remove</button>
                </div>
              ))}
              <div style={{ marginTop: '1rem', display: 'flex', gap: '.5rem' }}>
                <button className="wc-btn-primary" onClick={complete} disabled={busy || items.length === 0}>Complete Claim</button>
              </div>
            </div>
          </>
        )}

        {step === STEP.DONE && claim && (
          <div className="wc-card">
            <h2>Claim {claim.claim_number} completed</h2>
            <p>{claim.claim_type === 'replacement'
              ? 'Original warranty marked Claimed; the replacement carries the original end date.'
              : 'Original warranty marked Claimed; repair parts deducted from inventory.'}</p>
            <button className="wc-btn-primary" onClick={downloadSlip}>Download Claim Slip</button>
            <button className="wc-btn-secondary" style={{ marginLeft: '.5rem' }} onClick={() => window.location.reload()}>New Claim</button>
          </div>
        )}
      </div>
    </AdminLayout>
  );
}
