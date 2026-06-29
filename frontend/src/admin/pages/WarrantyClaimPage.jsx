import React, { useState, useRef, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import { warrantyClaimsAPI, customersAPI, locationsAPI, getApiBaseUrl, getToken } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/issuance_scan.css';

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
  const [feedback, setFeedback] = useState(null); // { ok: bool, message: string }
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
        if (list.length === 1) {
          const only = list[0];
          setForm((f) => ({ ...f, location_id: String(only.location_id ?? only.id) }));
        }
      } catch { setLocations([]); }
    })();
  }, []);

  useEffect(() => {
    if (step === STEP.ITEMS) {
      const t = setTimeout(() => scanRef.current?.focus(), 80);
      return () => clearTimeout(t);
    }
  }, [step, claim]);

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
      setFeedback(null);
      setStep(STEP.ITEMS);
    } catch (err) {
      setError(err.message || 'Could not start the claim.');
    } finally { setBusy(false); }
  };

  const addItem = async () => {
    if (!scanValue.trim() || busy) return;
    setBusy(true); setFeedback(null);
    try {
      const res = await warrantyClaimsAPI.addItem(claim.claim_id, {
        barcode: scanValue.trim(),
        quantity: claim.claim_type === 'repair' ? Number(qty) || 1 : undefined,
      });
      setClaim(res?.data ?? res);
      setScanValue(''); setQty(1);
      setFeedback({ ok: true, message: 'Item added.' });
      scanRef.current?.focus();
    } catch (err) {
      setFeedback({ ok: false, message: err.message || 'Could not add the item.' });
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
      setFeedback({ ok: false, message: err.message || 'Could not complete the claim.' });
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
      })
      .catch(() => toast.error('Could not download the claim slip.'));
  };

  const items = claim?.items ?? [];
  const isReplacement = claim?.claim_type === 'replacement';
  const typeBadgeClass = isReplacement ? 'isp-type-warranty' : 'isp-type-business';

  const Steps = () => (
    <div className="isp-steps">
      <div className={`isp-step ${step === STEP.FORM ? 'isp-step-active' : ''} ${step > STEP.FORM ? 'isp-step-done' : ''}`}>
        <div className="isp-step-dot">{step > STEP.FORM ? '✓' : '1'}</div>
        <div className="isp-step-label">Claim Details</div>
      </div>
      <div className={`isp-step-line ${step > STEP.FORM ? 'isp-step-line-done' : ''}`} />
      <div className={`isp-step ${step === STEP.ITEMS ? 'isp-step-active' : ''} ${step > STEP.ITEMS ? 'isp-step-done' : ''}`}>
        <div className="isp-step-dot">{step > STEP.ITEMS ? '✓' : '2'}</div>
        <div className="isp-step-label">{isReplacement ? 'Replacement Unit' : 'Repair Parts'}</div>
      </div>
      <div className={`isp-step-line ${step > STEP.ITEMS ? 'isp-step-line-done' : ''}`} />
      <div className={`isp-step ${step === STEP.DONE ? 'isp-step-active' : ''}`}>
        <div className="isp-step-dot">{step === STEP.DONE ? '✓' : '3'}</div>
        <div className="isp-step-label">Done</div>
      </div>
    </div>
  );

  return (
    <AdminLayout>
      <div className="ak-page isp-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">After-sales · Warranty</p>
              <h1 className="ak-title">Warranty Claim</h1>
              <p className="ak-sub">File and process a warranty claim for an issued product.</p>
            </div>
          </div>
        </header>
        <Steps />

        {/* ── STEP 1: CLAIM DETAILS ── */}
        {step === STEP.FORM && (
          <div className="isp-card">
            <h2 className="isp-card-title">New Warranty Claim</h2>
            <form className="isp-form" onSubmit={startClaim}>
              <div className="isp-form-group" style={{ position: 'relative' }}>
                <label>Customer</label>
                <input
                  value={form.customer_label}
                  onChange={(e) => searchCustomers(e.target.value)}
                  placeholder="Search customer by name or code…"
                  autoComplete="off"
                />
                {customerResults.length > 0 && (
                  <div className="isp-dropdown">
                    {customerResults.map((c) => (
                      <button type="button" key={c.customer_id} className="isp-dropdown-item" onClick={() => pickCustomer(c)}>
                        <span className="isp-dropdown-name">{c.customer_name}</span>
                        <span className="isp-dropdown-company">{c.company_name || c.customer_code}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>

              <div className="isp-form-group">
                <label>Serial Number (scan or type)</label>
                <input
                  value={form.serial_number}
                  onChange={(e) => setForm((f) => ({ ...f, serial_number: e.target.value }))}
                  placeholder="Serial number of the unit being claimed"
                />
              </div>

              <div className="isp-form-row">
                <div className="isp-form-group">
                  <label>Claim Type</label>
                  <select value={form.claim_type} onChange={(e) => setForm((f) => ({ ...f, claim_type: e.target.value }))}>
                    <option value="repair">Repair (use spare parts)</option>
                    <option value="replacement">Replacement (give a new unit)</option>
                  </select>
                </div>
                <div className="isp-form-group">
                  <label>Location</label>
                  <select value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value }))}>
                    <option value="">Select location…</option>
                    {locations.map((l) => {
                      const id = l.location_id ?? l.id;
                      return <option key={id} value={String(id)}>{l.location_name || l.name}</option>;
                    })}
                  </select>
                </div>
              </div>

              <div className="isp-form-group">
                <label>Notes / Problem</label>
                <textarea rows={2} value={form.notes} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} placeholder="Describe the problem reported by the customer" />
              </div>

              <div className="isp-info-banner">
                Only an <strong>active</strong> warranty for this serial can be claimed. A warranty can be claimed once;
                a replacement carries the original warranty's remaining coverage.
              </div>

              {error && <div className="isp-feedback isp-feedback-err">{error}</div>}

              <div className="isp-form-actions">
                <button type="submit" className="isp-btn-primary" disabled={busy}>
                  {busy ? 'Validating…' : 'Start Claim'}
                </button>
              </div>
            </form>
          </div>
        )}

        {/* ── STEP 2: ITEMS ── */}
        {step === STEP.ITEMS && claim && (
          <>
            <div className="isp-session-header">
              <div className="isp-session-info">
                <span className="isp-session-dr">{claim.claim_number}</span>
                <span className="isp-session-sep">|</span>
                <span className={`isp-type-badge ${typeBadgeClass}`}>{claim.claim_type}</span>
                <span className="isp-session-sep">|</span>
                <span>Serial {claim.serial_number}</span>
                {claim.customer?.customer_name && (
                  <>
                    <span className="isp-session-sep">|</span>
                    <span>{claim.customer.customer_name}</span>
                  </>
                )}
              </div>
            </div>

            <div className="isp-card isp-scan-card">
              <h2 className="isp-card-title">
                {isReplacement ? 'Scan the replacement unit' : 'Scan repair parts'}
              </h2>

              <div className="isp-scan-input-wrap">
                <input
                  ref={scanRef}
                  className="isp-scan-input"
                  value={scanValue}
                  onChange={(e) => setScanValue(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addItem(); } }}
                  placeholder={isReplacement ? 'Scan the replacement unit serial' : 'Scan a consumable spare-part barcode'}
                  disabled={busy}
                />
                {busy && <span className="isp-scan-spinner" />}
              </div>

              {!isReplacement && (
                <div className="isp-qty-row" style={{ gridTemplateColumns: '160px auto', alignItems: 'end' }}>
                  <div className="isp-form-group">
                    <label>Quantity</label>
                    <input type="number" min="1" value={qty} onChange={(e) => setQty(e.target.value)} disabled={busy} />
                  </div>
                  <div className="isp-qty-actions" style={{ justifyContent: 'flex-start' }}>
                    <button type="button" className="isp-btn-secondary" onClick={addItem} disabled={busy || !scanValue.trim()}>Add Part</button>
                  </div>
                </div>
              )}

              {isReplacement && (
                <div className="isp-scan-hints">
                  <span>A replacement claim takes exactly one unit.</span>
                </div>
              )}

              {feedback && (
                <div className={`isp-feedback ${feedback.ok ? 'isp-feedback-ok' : 'isp-feedback-err'}`}>
                  {feedback.message}
                </div>
              )}
            </div>

            <div className="isp-card">
              <h2 className="isp-card-title">Items ({items.length})</h2>
              {items.length === 0 ? (
                <div className="isp-scan-hints"><span>No items added yet.</span></div>
              ) : (
                <div className="isp-table-wrap">
                  <table className="isp-table">
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th>Serial</th>
                        <th>Qty</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.map((it) => (
                        <tr key={it.detail_id}>
                          <td>
                            <div className="isp-td-product">{it.product_name}</div>
                            {it.model_code && <div className="isp-td-code">{it.model_code}</div>}
                          </td>
                          <td>{it.serial_number ? <span className="isp-serial">{it.serial_number}</span> : '—'}</td>
                          <td>{it.quantity_issued}{it.quantity_unit ? ` ${it.quantity_unit}` : ''}</td>
                          <td style={{ textAlign: 'right' }}>
                            <button type="button" className="isp-btn-remove" onClick={() => removeItem(it.detail_id)} disabled={busy}>Remove</button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <div className="isp-form-actions">
                <button type="button" className="isp-btn-complete" onClick={complete} disabled={busy || items.length === 0}>
                  Complete Claim
                </button>
              </div>
            </div>
          </>
        )}

        {/* ── STEP 3: DONE ── */}
        {step === STEP.DONE && claim && (
          <div className="isp-card">
            <h2 className="isp-card-title">Claim {claim.claim_number} completed</h2>
            <p style={{ color: 'var(--text-secondary, #6b7280)', marginTop: 0 }}>
              {isReplacement
                ? 'The original warranty is marked Claimed; the replacement carries the original end date and remains claimable.'
                : 'The original warranty is marked Claimed and the repair parts were deducted from inventory.'}
            </p>
            <div className="isp-form-actions" style={{ justifyContent: 'flex-start' }}>
              <button type="button" className="isp-btn-primary" onClick={downloadSlip}>Download Claim Slip</button>
              <button type="button" className="isp-btn-secondary" onClick={() => window.location.reload()}>New Claim</button>
            </div>
          </div>
        )}
      </div>
    </AdminLayout>
  );
}
