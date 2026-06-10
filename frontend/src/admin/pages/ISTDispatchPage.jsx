import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { istAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import CameraScanner from '../components/CameraScanner';
import '../styles/po_recommendation.css';
import '../styles/po_receiving.css';
import '../styles/dashboard_air.css';

const fmtDate = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v).slice(0, 10);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};
const userName = (u) =>
  u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';

const isLineSerialized = (detail) =>
  detail?.is_serialized || detail?.product?.is_serialized || detail?.product?.product_type === 'appliance';

/**
 * Source-side dispatch scanner for an approved IST.
 * Click a product line on the left, then scan each unit into it (camera or input);
 * confirm dispatch (-> In Transit) when done. Mirrors the Receive PO barcode-scan UX.
 */
const ISTDispatchPage = () => {
  const { id }   = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [ist,         setIst]         = useState(null);
  const [loading,     setLoading]     = useState(true);
  const [error,       setError]       = useState('');
  const [submitting,  setSubmitting]  = useState(false);
  const [dispatched,  setDispatched]  = useState(false);

  const [sessionScans,  setSessionScans]  = useState({}); // { [did]: [{ scan_id, barcode, quantity }] }
  const [scannedTotals, setScannedTotals] = useState({}); // { [did]: total_scanned }
  const [activeLine,    setActiveLine]     = useState(null); // selected transfer_detail_id
  const [scanInput,     setScanInput]      = useState('');
  const [scanning,      setScanning]       = useState(false);

  // Keep a fresh snapshot of totals for the auto-advance lookup (avoids stale closure).
  const scannedTotalsRef = useRef({});
  useEffect(() => { scannedTotalsRef.current = scannedTotals; }, [scannedTotals]);

  const fetchIst = useCallback(() => {
    setLoading(true);
    setError('');
    istAPI.getById(id)
      .then(async (r) => {
        const data = r?.data ?? r;
        setIst(data);

        const totalsInit = {};
        (data?.details || []).forEach((d) => {
          totalsInit[d.transfer_detail_id] = d.quantity_scanned ?? 0;
        });
        setScannedTotals(totalsInit);

        // Restore previously-recorded scans so they remain removable across refreshes.
        try {
          const st = await istAPI.scanStatus(id);
          const stData = st?.data ?? st;
          const history = stData?.scan_history || [];
          const pidToDid = {};
          (data?.details || []).forEach((d) => {
            if (pidToDid[d.product_id] == null) pidToDid[d.product_id] = d.transfer_detail_id;
          });
          const grouped = {};
          history.forEach((h) => {
            const did = pidToDid[h.product_id];
            if (did == null) return;
            (grouped[did] = grouped[did] || []).push({
              scan_id: h.id,
              barcode: h.barcode,
              quantity: h.quantity ?? 1,
            });
          });
          setSessionScans(grouped);
        } catch { /* progress restore is best-effort */ }
      })
      .catch((e) => setError(e?.message || 'This IST is not available for dispatch.'))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { fetchIst(); }, [fetchIst]);

  const from = ist?.fromLocation?.location_name || '—';
  const to   = ist?.toLocation?.location_name   || '—';

  const totalScanned = Object.values(scannedTotals).reduce((s, n) => s + (n || 0), 0);

  const activeDetail = (ist?.details || []).find((d) => d.transfer_detail_id === activeLine) || null;

  // ── Record one scan into the selected line ──
  const handleLineScan = useCallback(async (code) => {
    const barcode = String(code || '').trim();
    if (!barcode) return;
    if (activeLine == null) {
      toast.error('Select a product first', 'Click an item on the left, then scan.');
      return;
    }
    setScanning(true);
    try {
      const result = await istAPI.scan(id, { barcode, transfer_detail_id: activeLine });
      const data = result?.data ?? result;
      const did  = data.transfer_detail_id ?? activeLine;
      setSessionScans((prev) => ({
        ...prev,
        [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: data.quantity ?? 1 }],
      }));
      // Update the totals ref synchronously so rapid back-to-back scans (and the
      // auto-advance below) read fresh values without waiting for the effect.
      const nextTotals = { ...scannedTotalsRef.current, [did]: data.total_scanned };
      scannedTotalsRef.current = nextTotals;
      setScannedTotals(nextTotals);
      toast.success(`Scanned ${data.product_name || ''}`.trim());

      // Auto-advance to the next incomplete line when this one is fully scanned.
      if (data.remaining === 0) {
        const next = (ist?.details || []).find((d) =>
          d.transfer_detail_id !== did && (nextTotals[d.transfer_detail_id] || 0) < (d.quantity_requested || 0)
        );
        setActiveLine(next ? next.transfer_detail_id : null);
      }
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Scan rejected.');
      toast.error('Scan rejected', msg);
    } finally {
      setScanning(false);
    }
  }, [id, activeLine, ist]);

  const submitManual = async () => {
    const v = scanInput.trim();
    if (!v) return;
    setScanInput('');
    await handleLineScan(v);
  };

  const handleRemoveScan = async (did, scan) => {
    try {
      await istAPI.removeScan(id, scan.scan_id);
      setSessionScans((prev) => {
        const existing = prev[did] || [];
        return { ...prev, [did]: existing.filter((s) => s.scan_id !== scan.scan_id) };
      });
      setScannedTotals((prev) => ({ ...prev, [did]: Math.max(0, (prev[did] || 0) - (scan.quantity || 1)) }));
    } catch (err) {
      toast.error('Could not remove scan', err?.message || 'Failed to remove scan.');
    }
  };

  const handleConfirm = async () => {
    if (totalScanned < 1) {
      toast.error("Can't dispatch yet", 'Scan at least one unit before confirming dispatch.');
      return;
    }
    if (!(await confirmAction({
      title: `Confirm dispatch of ${ist?.transfer_number || 'this IST'}?`,
      text: 'Scanned units will be marked In Transit.',
      confirmText: 'Confirm dispatch',
      tone: 'primary',
    }))) return;
    setSubmitting(true);
    try {
      await istAPI.confirmDispatch(id);
      setDispatched(true);
    } catch (err) {
      toast.error("Couldn't confirm dispatch", err?.message || 'Failed to confirm dispatch.');
    } finally {
      setSubmitting(false);
    }
  };

  // Flatten session scans into a single list for the "SCANNED ITEMS" panel.
  const scannedFlat = [];
  (ist?.details || []).forEach((d) => {
    const name = d.product?.product_name || `Product #${d.product_id}`;
    (sessionScans[d.transfer_detail_id] || []).forEach((s) => {
      scannedFlat.push({ ...s, did: d.transfer_detail_id, productName: name });
    });
  });

  if (dispatched) {
    return (
      <AdminLayout>
        <div className="po-recommendation-page">
          <div className="po-details-card" style={{ textAlign: 'center', padding: '40px 32px' }}>
            <div style={{ fontSize: 40, marginBottom: 16 }}>🚚</div>
            <h2 style={{ color: '#16a34a', fontWeight: 700, marginBottom: 8 }}>Dispatch Confirmed</h2>
            <p style={{ color: '#475569', marginBottom: 24 }}>
              {ist?.transfer_number} is now <strong>In Transit</strong>. The destination can receive it.
            </p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
              <button type="button" className="po-btn" style={{ background: '#1e293b', color: '#fff' }} onClick={() => navigate(`/admin/ist/${id}`)}>
                View IST
              </button>
              <button type="button" className="po-btn" style={{ background: '#f1f5f9', color: '#475569' }} onClick={() => navigate('/admin/ist')}>
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
                  <rect x="1" y="3" width="15" height="13" /><polygon points="16 8 20 8 23 11 23 16 16 16 16 8" />
                  <circle cx="5.5" cy="18.5" r="2.5" /><circle cx="18.5" cy="18.5" r="2.5" />
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">
                  {loading ? 'Loading…' : `DISPATCH ITEMS — ${ist?.transfer_number || ''}`}
                </h1>
                <p className="po-page-subtitle">Click a product, then scan each unit leaving the source</p>
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
            <div className="po-details-card" style={{ marginBottom: 16 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '16px 24px' }}>
                {[
                  { label: 'IST NUMBER',    value: ist.transfer_number, bold: true },
                  { label: 'FROM',          value: from, bold: true },
                  { label: 'TO',            value: to, bold: true },
                  { label: 'TRANSFER DATE', value: fmtDate(ist.transfer_date) },
                  { label: 'PREPARED BY',   value: userName(ist.preparedBy) },
                ].map(({ label, value, bold }) => (
                  <div key={label} className="po-field">
                    <span className="po-field-label">{label}</span>
                    <span style={{ fontSize: 14, color: '#1e293b', fontWeight: bold ? 700 : 500 }}>{value}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* ── Two-column scan workspace (mirrors Receive PO) ── */}
            <div className="bsr-workflow">

              {/* LEFT: clickable product checklist */}
              <div className="bsr-left">
                <div className="bsr-card">
                  <div className="bsr-card-head">
                    <div className="bsr-card-icon bsr-card-icon--box">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9810FA" strokeWidth="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                      </svg>
                    </div>
                    <span className="bsr-card-title">ITEMS TO DISPATCH</span>
                    <span className="bsr-scanned-count">{totalScanned} scanned</span>
                  </div>
                  <div className="bsr-items-list">
                    {(ist.details || []).length === 0 ? (
                      <p className="bsr-items-empty">No line items on this IST.</p>
                    ) : (ist.details || []).map((detail) => {
                      const did       = detail.transfer_detail_id;
                      const target    = detail.quantity_requested ?? 0;
                      const scanned   = scannedTotals[did] ?? 0;
                      const remaining = Math.max(0, target - scanned);
                      const done      = remaining === 0 && target > 0;
                      const isActive  = activeLine === did;
                      const serialized = isLineSerialized(detail);
                      const name      = detail.product?.product_name || `Product #${detail.product_id}`;
                      const sku       = detail.product?.product_code || '—';
                      const pct       = target > 0 ? Math.min(100, (scanned / target) * 100) : 0;
                      return (
                        <div
                          key={did}
                          className={`bsr-item-row bsr-item-row--clickable${isActive ? ' bsr-item-row--active' : ''}${done ? ' bsr-item-row--done' : ''}`}
                          onClick={() => !done && setActiveLine(did)}
                          title={done ? 'Fully scanned' : 'Click to scan this item'}
                        >
                          <div className="bsr-item-info">
                            <span className="bsr-item-name">{name}</span>
                            <span className="bsr-item-sku">SKU: {sku}</span>
                            <div className="bsr-item-badges">
                              {serialized
                                ? <span className="bsr-serialized-badge">SERIAL</span>
                                : <span className="bsr-bulk-badge">BULK</span>}
                              {isActive && <span className="bsr-active-badge">SCANNING NOW</span>}
                            </div>
                          </div>
                          <div className="bsr-item-progress-row">
                            <span className="bsr-item-progress-label">Progress:</span>
                            <span className={`bsr-item-qty ${done ? 'bsr-item-qty--done' : 'bsr-item-qty--zero'}`}>
                              {scanned} / {target} units
                            </span>
                          </div>
                          <div className="bsr-progress-bar-wrap">
                            <div className={`bsr-progress-bar-fill ${done ? 'bsr-progress-bar-fill--done' : ''}`} style={{ width: `${pct}%` }}/>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              </div>

              {/* RIGHT: scan panel + scanned items */}
              <div className="bsr-right">
                <div className="bsr-card">
                  <div className="bsr-card-head">
                    <div className="bsr-card-icon bsr-card-icon--scan">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9810FA" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M3 5v4"/><path d="M3 15v4"/><path d="M7 5v2"/><path d="M7 17v2"/>
                        <path d="M7 9v6"/><path d="M11 5v14"/><path d="M15 5v2"/><path d="M15 17v2"/>
                        <path d="M15 9v6"/><path d="M19 5v4"/><path d="M19 15v4"/>
                      </svg>
                    </div>
                    <div>
                      <span className="bsr-card-title">SCAN BARCODE</span>
                      {activeDetail ? (
                        <p className="bsr-card-subtitle">
                          <strong>{activeDetail.product?.product_name || 'Selected item'}</strong>
                        </p>
                      ) : (
                        <p className="bsr-card-subtitle bsr-card-subtitle--warn">Select an item on the left to begin</p>
                      )}
                    </div>
                  </div>

                  <CameraScanner
                    onDecode={handleLineScan}
                    mountId="ist-dispatch-scanner"
                    storageKey="bs_inventory_camera_device_id"
                    busy={submitting || scanning || activeLine == null}
                    hint="Select a product, then scan each unit leaving the source."
                  />

                  <div className="bsr-scan-row">
                    <input
                      type="text"
                      className={`bsr-scan-input${scanning ? ' bsr-scan-input--scanning' : ''}`}
                      placeholder={activeLine != null ? 'Scan or type barcode / serial…' : 'Select an item on the left first…'}
                      value={scanInput}
                      onChange={(e) => setScanInput(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submitManual(); } }}
                      disabled={scanning || activeLine == null}
                      autoComplete="off"
                    />
                    <button
                      type="button"
                      className="bsr-capture-btn"
                      onClick={submitManual}
                      disabled={scanning || activeLine == null || !scanInput.trim()}
                    >
                      {scanning ? 'Scanning…' : 'Submit'}
                    </button>
                  </div>
                </div>

                <div className="bsr-card">
                  <div className="bsr-card-head">
                    <div className="bsr-card-icon bsr-card-icon--cart">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9810FA" strokeWidth="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                      </svg>
                    </div>
                    <span className="bsr-card-title">SCANNED ITEMS</span>
                    <span className="bsr-scanned-count">{scannedFlat.length} {scannedFlat.length === 1 ? 'item' : 'items'}</span>
                  </div>
                  {scannedFlat.length === 0 ? (
                    <div className="bsr-scanned-empty">
                      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" strokeWidth="1.5">
                        <rect x="2" y="5" width="3" height="14" rx="1"/><rect x="7" y="5" width="1.5" height="14" rx="0.5"/>
                        <rect x="10" y="5" width="3" height="14" rx="1"/><rect x="15" y="5" width="1.5" height="14" rx="0.5"/>
                        <rect x="18" y="5" width="3" height="14" rx="1"/>
                      </svg>
                      <p>No items scanned yet</p>
                    </div>
                  ) : (
                    <div className="bsr-scanned-list">
                      {scannedFlat.map((s) => (
                        <div key={s.scan_id ?? `${s.did}-${s.barcode}`} className="bsr-scanned-item">
                          <div className="bsr-scanned-item-info">
                            <span className="bsr-scanned-item-name">{s.productName}</span>
                            <span className="bsr-scanned-item-serial">{s.barcode}{s.quantity > 1 ? ` ×${s.quantity}` : ''}</span>
                          </div>
                          {s.scan_id != null && (
                            <button
                              type="button"
                              onClick={() => handleRemoveScan(s.did, s)}
                              style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#dc2626', fontSize: 11, padding: '2px 6px', borderRadius: 3 }}
                              title="Remove this scan"
                            >
                              Remove
                            </button>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="po-details-card" style={{ margin: '16px 0' }}>
              <div className="po-field">
                <span className="po-field-label">DISPATCHED BY</span>
                <span style={{ fontSize: 14, color: '#1e293b', fontWeight: 500 }}>{userName(user)}</span>
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
                  background: totalScanned < 1 ? '#94a3b8' : '#1e293b',
                  color: '#fff', minWidth: 180,
                  cursor: totalScanned < 1 ? 'not-allowed' : 'pointer',
                }}
                onClick={handleConfirm}
                disabled={submitting || totalScanned < 1}
                title={totalScanned < 1 ? 'Scan at least one unit before confirming' : ''}
              >
                {submitting ? 'Confirming…' : 'Confirm Dispatch'}
              </button>
            </div>
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default ISTDispatchPage;
