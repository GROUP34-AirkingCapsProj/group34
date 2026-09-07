import React, { useState, useRef, useEffect } from 'react';
import AdminLayout from '../components/AdminLayout';
import { warrantyClaimsAPI, locationsAPI, openApiFile } from '../services/api';
import CameraScanner from '../components/CameraScanner';
import { AddSetPath, AddCustomPath } from '../components/issuance/OrderBuilder';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import { toast } from '../utils/toast';
import '../styles/issuance_scan.css';

const STEP = { FORM: 1, ITEMS: 2, DONE: 3 };

export default function WarrantyClaimPage() {
  const { user } = useAuth();
  const { locationId: userLocationId } = useUserLocation();
  const roleKey = getRoleKey(user);
  const canSeeAllLocations = [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.AUDITOR].includes(roleKey);
  const [step, setStep] = useState(STEP.FORM);
  const [form, setForm] = useState({
    serial_number: '',
    claim_type: 'repair', notes: '', location_id: '',
  });
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
        const allLocations = Array.isArray(res?.data) ? res.data : [];
        const list = canSeeAllLocations
          ? allLocations
          : allLocations.filter((location) => String(location.location_id ?? location.id) === String(userLocationId));
        setLocations(list);
        if (!canSeeAllLocations && userLocationId) {
          setForm((f) => ({ ...f, location_id: String(userLocationId) }));
        } else if (list.length === 1) {
          const only = list[0];
          setForm((f) => ({ ...f, location_id: String(only.location_id ?? only.id) }));
        }
      } catch { setLocations([]); }
    })();
  }, [canSeeAllLocations, userLocationId]);

  useEffect(() => {
    if (step === STEP.ITEMS) {
      const t = setTimeout(() => scanRef.current?.focus(), 80);
      return () => clearTimeout(t);
    }
  }, [step, claim]);

  const startClaim = async (e) => {
    e.preventDefault();
    setError('');
    if (!form.serial_number.trim()) { setError('Enter or scan the serial number being claimed.'); return; }
    if (!form.location_id) { setError('Select a location.'); return; }
    setBusy(true);
    try {
      const res = await warrantyClaimsAPI.init({
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

  const addItem = async (decodedValue = null) => {
    const barcode = typeof decodedValue === 'string' ? decodedValue.trim() : scanValue.trim();
    if (!barcode || busy) return;
    setBusy(true); setFeedback(null);
    try {
      const res = await warrantyClaimsAPI.addItem(claim.claim_id, {
        barcode,
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

  const addCustomOrderLine = async (productId, quantityInPieces) => {
    setBusy(true); setFeedback(null);
    try {
      const res = await warrantyClaimsAPI.addCustomOrderLine(claim.claim_id, productId, quantityInPieces);
      setClaim(res?.data ?? res);
    } catch (err) {
      setFeedback({ ok: false, message: err.message || 'Could not add the product to the order.' });
    } finally { setBusy(false); }
  };

  const addSetOrder = async (setId, quantity) => {
    setBusy(true); setFeedback(null);
    try {
      const res = await warrantyClaimsAPI.addSetOrder(claim.claim_id, setId, quantity);
      setClaim(res?.data ?? res);
    } catch (err) {
      setFeedback({ ok: false, message: err.message || 'Could not add the set to the order.' });
    } finally { setBusy(false); }
  };

  const removeOrderLine = async (detailId) => {
    setBusy(true);
    try {
      const res = await warrantyClaimsAPI.removeOrderLine(claim.claim_id, detailId);
      setClaim(res?.data ?? res);
    } catch (err) { toast.error(err.message || 'Could not remove the order line.'); }
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

  const openClaimReceipt = (download = false) => openApiFile(
    warrantyClaimsAPI.receiptPdfPath(claim.claim_id, !download),
    {
      preview: !download,
      download,
      filename: `${claim.claim_number}.pdf`,
    },
  ).catch((e) => toast.error(e.message || 'Could not load the claim receipt.'));

  const items = claim?.items ?? [];
  const isReplacement = claim?.claim_type === 'replacement';
  const hasOrder = items.length > 0;
  const allFulfilled = hasOrder && items.every((item) => item.is_fulfilled);
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
              {/*
                  placeholder="Search customer by name or code…"
              */}
              <div className="isp-form-group">
                <label>Serial Number (scan or type)</label>
                <div style={{ display: 'flex', gap: 8, alignItems: 'stretch' }}>
                  <input
                    type="text"
                    name="warranty_serial_number"
                    value={form.serial_number}
                    onChange={(e) => setForm((f) => ({ ...f, serial_number: e.target.value }))}
                    onBlur={(e) => setForm((f) => ({ ...f, serial_number: e.target.value.trim() }))}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        e.currentTarget.form?.requestSubmit();
                      }
                    }}
                    placeholder="Serial number of the unit being claimed"
                    autoComplete="off"
                    style={{ flex: 1 }}
                  />
                  <button
                    type="submit"
                    className="isp-btn-primary"
                    disabled={busy || !form.serial_number.trim()}
                    style={{ whiteSpace: 'nowrap' }}
                  >
                    {busy ? 'Checking…' : 'Submit Serial'}
                  </button>
                </div>
              </div>
              <CameraScanner
                onDecode={(value) => setForm((f) => ({ ...f, serial_number: String(value || '').trim() }))}
                mountId="warranty-original-unit-camera"
                storageKey="bs_inventory_camera_device_id"
                busy={busy}
                hint="Scan the serial number on the customer's claimed unit."
              />

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
                  <select value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value }))} disabled={!canSeeAllLocations}>
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
                The customer and original issue-out are resolved automatically from this serial number.{' '}
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

            <div className="isp-card" style={{ marginBottom: 16 }}>
              <h2 className="isp-card-title">Claimed Issue-out Item</h2>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12 }}>
                <div><strong>Product</strong><br />{claim.product?.product_name || '—'}{claim.product?.product_code ? ` · ${claim.product.product_code}` : ''}</div>
                <div><strong>Original serial</strong><br />{claim.serial_number}</div>
                <div><strong>Issue-out reference</strong><br />{claim.warranty?.issuance_reference || '—'}</div>
                <div><strong>Warranty coverage</strong><br />{claim.warranty?.warranty_start_date || '—'} to {claim.warranty?.warranty_end_date || '—'}</div>
                <div><strong>Remaining coverage</strong><br />{claim.warranty?.warranty_duration_label || '—'}</div>
                <div><strong>Inventory location</strong><br />{claim.location_name || '—'}</div>
              </div>
            </div>

            <div className="isp-card" style={{ marginBottom: 16 }}>
              <h2 className="isp-card-title">1. Build warranty order</h2>
              <p className="ak-sub">Choose the required stock first. Availability is shown for {claim.location_name || 'this location'} before any scan.</p>
              {!isReplacement && <AddSetPath disabled={busy} onAdd={addSetOrder} />}
              <div style={{ marginTop: 14 }}>
                <AddCustomPath disabled={busy} onAdd={addCustomOrderLine} />
              </div>
              {isReplacement && <div className="isp-info-banner">A replacement order accepts exactly one serialized unit.</div>}
            </div>

            <div className="isp-card">
              <h2 className="isp-card-title">Warranty Order ({items.length})</h2>
              {items.length === 0 ? (
                <div className="isp-scan-hints"><span>Add a product{!isReplacement ? ' or set' : ''} to begin.</span></div>
              ) : (
                <div className="isp-table-wrap">
                  <table className="isp-table">
                    <thead><tr><th>Product / Code</th><th>Ordered</th><th>Available</th><th>Fulfilled</th><th>Status</th><th></th></tr></thead>
                    <tbody>{items.map((it) => (
                      <tr key={it.detail_id}>
                        <td><div className="isp-td-product">{it.product_name}{it.product_code ? ` · ${it.product_code}` : ''}</div></td>
                        <td>{it.quantity_requested} {it.quantity_unit || 'unit'}</td>
                        <td>{it.available_quantity}</td>
                        <td>{it.quantity_issued} / {it.quantity_requested}</td>
                        <td style={{ color: it.is_fulfilled ? '#15803d' : (it.has_sufficient_stock ? '#92400e' : '#b91c1c'), fontWeight: 700 }}>
                          {it.is_fulfilled ? 'Fulfilled' : (it.has_sufficient_stock ? 'Ready to scan' : 'Insufficient stock')}
                        </td>
                        <td style={{ textAlign: 'right' }}>
                          {Number(it.quantity_issued) > 0
                            ? <button type="button" className="isp-btn-remove" onClick={() => removeItem(it.detail_id)} disabled={busy}>Reverse scan</button>
                            : <button type="button" className="isp-btn-remove" onClick={() => removeOrderLine(it.detail_id)} disabled={busy}>Remove</button>}
                        </td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </div>

            {hasOrder && !allFulfilled && <div className="isp-card isp-scan-card">
              <h2 className="isp-card-title">
                2. {isReplacement ? 'Scan the ordered replacement unit' : 'Scan ordered repair parts'}
              </h2>

              <CameraScanner
                onDecode={(value) => addItem(String(value || ''))}
                mountId="warranty-claim-item-camera"
                storageKey="bs_inventory_camera_device_id"
                busy={busy}
                hint={isReplacement ? 'Scan the in-stock replacement unit serial.' : 'Scan a stocked repair-part barcode.'}
              />

              <div className="isp-scan-input-wrap">
                <input
                  ref={scanRef}
                  className="isp-scan-input"
                  value={scanValue}
                  onChange={(e) => setScanValue(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addItem(); } }}
                  placeholder={isReplacement ? 'Scan the replacement unit serial' : 'Scan an installation material spare-part barcode'}
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
            </div>}

            <div className="isp-card">
              <h2 className="isp-card-title">Items ({items.length})</h2>
              {items.length === 0 ? (
                <div className="isp-scan-hints"><span>No items added yet.</span></div>
              ) : (
                <div className="isp-table-wrap">
                  <table className="isp-table">
                    <thead>
                      <tr>
                        <th>Product / Code</th>
                        <th>Serial</th>
                        <th>Qty</th>
                        <th>Unit Cost</th>
                        <th>Amount</th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.map((it) => (
                        <tr key={it.detail_id}>
                          <td>
                            <div className="isp-td-product">{it.product_name}{it.product_code ? ` · ${it.product_code}` : ''}</div>
                          </td>
                          <td>{it.serial_number ? <span className="isp-serial">{it.serial_number}</span> : '—'}</td>
                          <td>{it.quantity_issued}{it.quantity_unit ? ` ${it.quantity_unit}` : ''}</td>
                          <td>₱{Number(it.unit_cost || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                          <td>₱{Number(it.line_cost || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</td>
                          <td style={{ textAlign: 'right' }}>
                            {Number(it.quantity_issued) > 0 && (
                              <button type="button" className="isp-btn-remove" onClick={() => removeItem(it.detail_id)} disabled={busy}>Reverse scan</button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <div className="isp-form-actions">
                <button type="button" className="isp-btn-complete" onClick={complete} disabled={busy || !allFulfilled}>
                  Complete Claim
                </button>
              </div>
              <div style={{ textAlign: 'right', fontWeight: 700, marginTop: 12 }}>
                Total claim cost: ₱{Number(claim.parts_cost || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
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
                : 'The repaired unit was returned to the customer and its original warranty remains active for the remaining coverage.'}
            </p>
            <div className="isp-form-actions" style={{ justifyContent: 'flex-start' }}>
              <button type="button" className="isp-btn-secondary" onClick={() => openClaimReceipt(false)}>View Receipt</button>
              <button type="button" className="isp-btn-primary" onClick={() => openClaimReceipt(true)}>Download PDF</button>
              <button type="button" className="isp-btn-secondary" onClick={() => window.location.reload()}>New Claim</button>
            </div>
          </div>
        )}
      </div>
    </AdminLayout>
  );
}
