import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { istAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/po_recommendation.css';
import '../styles/dashboard_air.css';
import DateInput from '../components/DateInput';

const fmtDate = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v).slice(0, 10);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};
const fmtDateTime = (v) => {
  if (!v) return '—';
  try { return new Date(v).toLocaleString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  catch { return String(v).slice(0, 16).replace('T', ' '); }
};
const userName = (u) =>
  u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';
const todayIso = () => new Date().toISOString().slice(0, 10);

// ── per-detail scan state shape: { barcode, qty, scanning, feedback, error, scans[] }
const initDetailState = () => ({ barcode: '', qty: 1, scanning: false, feedback: null, error: null });

const ISTReceivePage = () => {
  const { id }   = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [ist,          setIst]          = useState(null);
  const [loading,      setLoading]      = useState(true);
  const [error,        setError]        = useState('');
  const [submitting,   setSubmitting]   = useState(false);
  const [confirmed,    setConfirmed]    = useState(false);

  // Received date for confirm
  const [receivedDate, setReceivedDate] = useState(todayIso());

  // Per-detail UI state: barcode input / qty / scanning status / inline feedback
  const [detailUi, setDetailUi] = useState({}); // { [transfer_detail_id]: { barcode, qty, scanning, feedback, error } }

  // Scans recorded in this session: { [transfer_detail_id]: [{ scan_id, barcode, quantity }] }
  const [sessionScans, setSessionScans] = useState({});

  // Running received totals (from server responses)
  const [receivedTotals, setReceivedTotals] = useState({}); // { [transfer_detail_id]: quantity_received }

  const inputRefs = useRef({});

  const fetchIst = useCallback(() => {
    setLoading(true);
    setError('');
    istAPI.receiveForm(id)
      .then((r) => {
        const data = r?.data ?? r;
        setIst(data);
        // Initialize per-detail UI state
        const uiInit = {};
        const totalsInit = {};
        (data?.details || []).forEach((d) => {
          uiInit[d.transfer_detail_id] = initDetailState();
          totalsInit[d.transfer_detail_id] = d.quantity_received ?? 0;
        });
        setDetailUi(uiInit);
        setReceivedTotals(totalsInit);
      })
      .catch((e) => setError(e?.message || 'This IST is not available for receiving.'))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { fetchIst(); }, [fetchIst]);

  const from = ist?.fromLocation?.location_name || '—';
  const to   = ist?.toLocation?.location_name   || '—';

  const dispatchEntry = (ist?.transfer_tracking || [])
    .filter((t) => (t.status_note || '').toLowerCase().includes('dispatched'))
    .sort((a, b) => new Date(b.recorded_at) - new Date(a.recorded_at))[0] || null;

  // Total received across all lines (from our running totals)
  const totalReceived = Object.values(receivedTotals).reduce((s, n) => s + (n || 0), 0);

  // ── Scan handler for a specific detail line ──
  const handleScan = async (detail) => {
    const did = detail.transfer_detail_id;
    const ui  = detailUi[did] || initDetailState();
    const barcode = ui.barcode.trim();

    if (!barcode) {
      setDetailUi((prev) => ({
        ...prev,
        [did]: { ...prev[did], error: 'Enter a barcode or serial number.' },
      }));
      return;
    }

    // For serialized: quantity is always 1; for consumable allow ui.qty
    const isSerialized = detail.is_serialized || (detail.product?.is_serialized);
    const quantity = isSerialized ? 1 : Math.max(1, parseInt(ui.qty, 10) || 1);

    setDetailUi((prev) => ({
      ...prev,
      [did]: { ...prev[did], scanning: true, error: null, feedback: null },
    }));

    try {
      const result = await istAPI.receiveScan(id, {
        barcode,
        transfer_detail_id: did,
        quantity,
      });

      const data = result?.data ?? result;
      const scanId         = data?.scan_id;
      const qtyReceived    = data?.quantity_received ?? 0;
      const remaining      = data?.remaining ?? 0;

      // Track scan in session
      setSessionScans((prev) => {
        const existing = prev[did] || [];
        return {
          ...prev,
          [did]: [...existing, { scan_id: scanId, barcode, quantity }],
        };
      });

      // Update running received total for this line
      setReceivedTotals((prev) => ({ ...prev, [did]: qtyReceived }));

      // Inline success feedback
      setDetailUi((prev) => ({
        ...prev,
        [did]: {
          ...initDetailState(),
          feedback: remaining > 0
            ? `Recorded. ${remaining} unit(s) remaining.`
            : 'All units for this line received.',
        },
      }));

      // Re-focus the input for fast scanning
      setTimeout(() => {
        inputRefs.current[did]?.focus();
      }, 50);
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Scan failed.';
      setDetailUi((prev) => ({
        ...prev,
        [did]: { ...prev[did], scanning: false, error: msg },
      }));
    }
  };

  // ── Remove a scan (undo) ──
  const handleRemoveScan = async (detailId, scan) => {
    try {
      await istAPI.removeReceiveScan(id, scan.scan_id);

      // Remove from session scans
      setSessionScans((prev) => {
        const existing = prev[detailId] || [];
        return {
          ...prev,
          [detailId]: existing.filter((s) => s.scan_id !== scan.scan_id),
        };
      });

      // Decrement running total by the scan's quantity
      setReceivedTotals((prev) => ({
        ...prev,
        [detailId]: Math.max(0, (prev[detailId] || 0) - scan.quantity),
      }));

      setDetailUi((prev) => ({
        ...prev,
        [detailId]: { ...(prev[detailId] || initDetailState()), feedback: 'Scan removed.' },
      }));
    } catch (err) {
      setDetailUi((prev) => ({
        ...prev,
        [detailId]: {
          ...(prev[detailId] || initDetailState()),
          error: err?.message || 'Failed to remove scan.',
        },
      }));
    }
  };

  // ── Confirm Receipt ──
  const handleConfirm = async () => {
    if (totalReceived < 1) {
      toast.error("Can't receive yet", 'Record at least one received unit before confirming.');
      return;
    }
    if (!(await confirmAction({
      title: `Confirm receipt of ${ist?.transfer_number || 'this IST'}?`,
      text: 'Scanned units will be added to destination stock.',
      confirmText: 'Confirm receipt',
      tone: 'primary',
    }))) return;
    setSubmitting(true);
    try {
      await istAPI.receive(id, { received_date: receivedDate });
      setConfirmed(true);
    } catch (err) {
      toast.error("Couldn't confirm receipt", err?.message || 'Failed to confirm receipt.');
    } finally {
      setSubmitting(false);
    }
  };

  // ── Post-confirm: show receipt options ──
  if (confirmed) {
    const pdfUrl = istAPI.receiptPdfUrl(id);
    return (
      <AdminLayout>
        <div className="po-recommendation-page">
          <div className="po-details-card" style={{ textAlign: 'center', padding: '40px 32px' }}>
            <div style={{ fontSize: 40, marginBottom: 16 }}>✓</div>
            <h2 style={{ color: '#16a34a', fontWeight: 700, marginBottom: 8 }}>
              Receipt Confirmed
            </h2>
            <p style={{ color: '#475569', marginBottom: 24 }}>
              {ist?.transfer_number} has been received successfully.
            </p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
              <button
                type="button"
                className="po-btn"
                style={{ background: '#1e293b', color: '#fff' }}
                onClick={() => navigate(`/admin/ist/${id}/receipt`)}
              >
                View On-Screen Receipt
              </button>
              <a
                href={pdfUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="po-btn"
                style={{ background: '#f59e0b', color: '#fff', textDecoration: 'none' }}
              >
                Download PDF Receipt
              </a>
              <button
                type="button"
                className="po-btn"
                style={{ background: '#f1f5f9', color: '#475569' }}
                onClick={() => navigate('/admin/ist')}
              >
                Back to IST List
              </button>
            </div>
          </div>
        </div>
      </AdminLayout>
    );
  }

  return (
    <AdminLayout>
      <div className="po-recommendation-page">

        {/* Header */}
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <button
                type="button"
                className="po-btn"
                style={{ padding: '6px 12px', fontSize: 12, marginRight: 12, flexShrink: 0 }}
                onClick={() => navigate(`/admin/ist/${id}`)}
              >
                ← Back
              </button>
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
                  strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">
                  {loading ? 'Loading…' : `RECEIVE ITEMS — ${ist?.transfer_number || ''}`}
                </h1>
                <p className="po-page-subtitle">Scan or enter barcodes to record each arriving unit</p>
              </div>
            </div>
          </div>
        </div>

        {loading && <div className="po-status-loading">Loading IST…</div>}
        {error && (
          <div className="po-submit-error">
            {error}
            <div style={{ marginTop: 10 }}>
              <button type="button" className="po-btn" style={{ fontSize: 12, padding: '6px 12px' }} onClick={() => navigate('/admin/ist')}>
                Back to IST List
              </button>
            </div>
          </div>
        )}

        {ist && !loading && (
          <>
            {/* ── IST Header (read-only) ── */}
            <div className="po-details-card" style={{ marginBottom: 16 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '16px 24px' }}>
                {[
                  { label: 'IST NUMBER',      value: ist.transfer_number,             bold: true },
                  { label: 'FROM',            value: from,                            bold: true },
                  { label: 'TO',              value: to,                              bold: true },
                  { label: 'TRANSFER DATE',   value: fmtDate(ist.transfer_date) },
                  { label: 'DISPATCHED BY',   value: userName(dispatchEntry?.recordedBy) },
                  { label: 'DISPATCHED DATE', value: fmtDateTime(dispatchEntry?.recorded_at) },
                ].map(({ label, value, bold }) => (
                  <div key={label} className="po-field">
                    <span className="po-field-label">{label}</span>
                    <span style={{ fontSize: 14, color: '#1e293b', fontWeight: bold ? 700 : 500 }}>{value}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* ── Progress summary ── */}
            <div className="po-details-card" style={{ marginBottom: 16, background: '#f0fdf4', border: '1px solid #bbf7d0' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                <span style={{ fontWeight: 600, color: '#15803d', fontSize: 14 }}>
                  Total received this session: {totalReceived}
                </span>
                <span style={{ fontSize: 12, color: '#64748b' }}>
                  {totalReceived < 1 ? 'Scan units below to record receipt' : 'Ready to confirm when done'}
                </span>
              </div>
            </div>

            {/* ── Per-line scan cards ── */}
            {(ist.details || []).map((detail) => {
              const did        = detail.transfer_detail_id;
              const ui         = detailUi[did] || initDetailState();
              const dispatched = detail.quantity_transferred ?? detail.quantity_scanned ?? 0;
              const received   = receivedTotals[did] ?? 0;
              const remaining  = Math.max(0, dispatched - received);
              const complete   = remaining === 0 && dispatched > 0;
              const scans      = sessionScans[did] || [];
              const isSerialized = detail.is_serialized || (detail.product?.is_serialized);
              const name       = detail.product?.product_name || `Product #${detail.product_id}`;
              const model      = detail.product?.model_name || detail.product?.model?.model_name || '';
              const serials    = Array.isArray(detail.item_serials) ? detail.item_serials : [];

              return (
                <div
                  key={did}
                  className="po-line-items-card"
                  style={{
                    marginBottom: 16,
                    border: complete ? '1px solid #86efac' : '1px solid #e2e8f0',
                    background: complete ? '#f0fdf4' : '#fff',
                  }}
                >
                  {/* Line header */}
                  <div className="po-line-items-header" style={{ borderBottom: '1px solid #f1f5f9', paddingBottom: 10, marginBottom: 12 }}>
                    <div style={{ flex: 1 }}>
                      <span className="po-line-items-title" style={{ display: 'block', marginBottom: 4 }}>
                        <span style={{ fontWeight: 700, fontSize: 14, color: '#1e293b' }}>{name}</span>
                        {model && <span style={{ fontSize: 11, color: '#94a3b8', marginLeft: 8 }}>{model}</span>}
                        {detail.product?.product_code && (
                          <span style={{ fontSize: 11, color: '#94a3b8', marginLeft: 8 }}>
                            SKU: {detail.product.product_code}
                          </span>
                        )}
                      </span>
                      <span style={{ fontSize: 12, color: '#64748b' }}>
                        Dispatched: <strong>{dispatched}</strong>
                        &nbsp;|&nbsp;
                        Received: <strong style={{ color: complete ? '#16a34a' : '#1e293b' }}>{received}</strong>
                        &nbsp;|&nbsp;
                        Remaining: <strong style={{ color: remaining > 0 ? '#b45309' : '#16a34a' }}>{remaining}</strong>
                      </span>
                    </div>
                    {complete && (
                      <span style={{
                        background: '#dcfce7', color: '#16a34a', border: '1px solid #86efac',
                        borderRadius: 12, fontSize: 10, fontWeight: 700, padding: '3px 10px',
                        letterSpacing: 0.5,
                      }}>
                        COMPLETE
                      </span>
                    )}
                  </div>

                  {/* Dispatched serials (from shipment) */}
                  {serials.length > 0 && (
                    <div style={{ marginBottom: 10 }}>
                      <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>DISPATCHED SERIALS: </span>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 4 }}>
                        {serials.map((sn) => (
                          <span key={sn} style={{
                            fontSize: 10, padding: '2px 6px', borderRadius: 4,
                            background: '#f1f5f9', color: '#475569', fontFamily: 'monospace',
                          }}>{sn}</span>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Scan input row */}
                  {!complete && (
                    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', flexWrap: 'wrap', marginBottom: ui.error || ui.feedback ? 8 : 0 }}>
                      <div style={{ flex: '1 1 200px' }}>
                        <input
                          ref={(el) => { inputRefs.current[did] = el; }}
                          type="text"
                          placeholder={isSerialized ? 'Scan or type serial number…' : 'Scan or type barcode…'}
                          value={ui.barcode}
                          onChange={(e) => setDetailUi((prev) => ({
                            ...prev,
                            [did]: { ...prev[did], barcode: e.target.value, error: null, feedback: null },
                          }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                              e.preventDefault();
                              handleScan(detail);
                            }
                          }}
                          disabled={ui.scanning}
                          style={{
                            width: '100%', padding: '8px 12px', borderRadius: 6,
                            border: `1px solid ${ui.error ? '#dc2626' : '#d1d5db'}`,
                            fontSize: 13, color: '#1e293b', boxSizing: 'border-box',
                            fontFamily: isSerialized ? 'monospace' : 'inherit',
                          }}
                          autoComplete="off"
                        />
                      </div>
                      {!isSerialized && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                          <span style={{ fontSize: 12, color: '#64748b', whiteSpace: 'nowrap' }}>Qty:</span>
                          <input
                            type="number"
                            min={1}
                            max={remaining}
                            value={ui.qty}
                            onChange={(e) => setDetailUi((prev) => ({
                              ...prev,
                              [did]: { ...prev[did], qty: Math.max(1, parseInt(e.target.value) || 1) },
                            }))}
                            disabled={ui.scanning}
                            style={{
                              width: 72, padding: '8px 6px', borderRadius: 6,
                              border: '1px solid #d1d5db', fontSize: 13, textAlign: 'center',
                            }}
                          />
                        </div>
                      )}
                      <button
                        type="button"
                        className="po-btn"
                        style={{ background: '#1e293b', color: '#fff', padding: '8px 18px', whiteSpace: 'nowrap' }}
                        onClick={() => handleScan(detail)}
                        disabled={ui.scanning || !ui.barcode.trim()}
                      >
                        {ui.scanning ? 'Adding…' : 'Add'}
                      </button>
                    </div>
                  )}

                  {/* Inline error / success */}
                  {ui.error && (
                    <div style={{ fontSize: 12, color: '#dc2626', marginBottom: 8, padding: '6px 10px', background: '#fef2f2', borderRadius: 4 }}>
                      {ui.error}
                    </div>
                  )}
                  {ui.feedback && (
                    <div style={{ fontSize: 12, color: '#16a34a', marginBottom: 8, padding: '6px 10px', background: '#f0fdf4', borderRadius: 4 }}>
                      {ui.feedback}
                    </div>
                  )}

                  {/* Session scans for this line — allow undo */}
                  {scans.length > 0 && (
                    <div style={{ marginTop: 10, borderTop: '1px solid #f1f5f9', paddingTop: 8 }}>
                      <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>SCANNED THIS SESSION:</span>
                      <div style={{ marginTop: 6, display: 'flex', flexDirection: 'column', gap: 4 }}>
                        {scans.map((s) => (
                          <div key={s.scan_id ?? s.barcode} style={{
                            display: 'flex', alignItems: 'center', gap: 8,
                            background: '#f8fafc', borderRadius: 4, padding: '4px 8px',
                          }}>
                            <span style={{ fontFamily: 'monospace', fontSize: 12, color: '#1e293b', flex: 1 }}>
                              {s.barcode}
                            </span>
                            <span style={{ fontSize: 11, color: '#64748b' }}>×{s.quantity}</span>
                            {s.scan_id != null && (
                              <button
                                type="button"
                                onClick={() => handleRemoveScan(did, s)}
                                style={{
                                  background: 'none', border: 'none', cursor: 'pointer',
                                  color: '#dc2626', fontSize: 11, padding: '2px 6px',
                                  borderRadius: 3,
                                }}
                                title="Remove this scan"
                              >
                                Remove
                              </button>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              );
            })}

            {/* ── Footer: date + confirm ── */}
            <div className="po-details-card" style={{ marginBottom: 16 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '16px 24px' }}>
                <div className="po-field">
                  <span className="po-field-label">RECEIVED BY</span>
                  <span style={{ fontSize: 14, color: '#1e293b', fontWeight: 500 }}>
                    {userName(user)}
                  </span>
                </div>
                <div className="po-field">
                  <label htmlFor="ist-received-date" className="po-field-label">RECEIVED DATE</label>
                  <DateInput
                    id="ist-received-date"
                    value={receivedDate}
                    onChange={(e) => setReceivedDate(e.target.value)}
                    required
                    style={{
                      padding: '7px 10px', borderRadius: 6, border: '1px solid #d1d5db',
                      fontSize: 14, color: '#1e293b', background: '#fff',
                    }}
                  />
                </div>
              </div>
            </div>


            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button
                type="button"
                className="po-btn"
                style={{ background: '#f1f5f9', color: '#475569' }}
                onClick={() => navigate(`/admin/ist/${id}`)}
                disabled={submitting}
              >
                Cancel
              </button>
              <button
                type="button"
                className="po-btn"
                style={{
                  background: totalReceived < 1 ? '#94a3b8' : '#1e293b',
                  color: '#fff', minWidth: 180,
                  cursor: totalReceived < 1 ? 'not-allowed' : 'pointer',
                }}
                onClick={handleConfirm}
                disabled={submitting || totalReceived < 1}
                title={totalReceived < 1 ? 'Scan at least one unit before confirming' : ''}
              >
                {submitting ? 'Confirming…' : 'Confirm Receipt'}
              </button>
            </div>
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default ISTReceivePage;
