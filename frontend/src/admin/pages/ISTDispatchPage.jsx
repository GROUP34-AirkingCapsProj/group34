import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { istAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import CameraScanner from '../components/CameraScanner';
import '../styles/po_recommendation.css';
import '../styles/dashboard_air.css';

const fmtDate = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v).slice(0, 10);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};
const userName = (u) =>
  u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';

const initDetailState = () => ({ barcode: '', scanning: false, feedback: null, error: null });

/**
 * Source-side dispatch scanner for an approved IST.
 * Scan/enter each unit leaving the source, then confirm dispatch (-> In Transit).
 * Lives under the Interbranch Transfer section (replaces the legacy scan-barcode transfer mode).
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

  const [detailUi,      setDetailUi]      = useState({}); // { [did]: { barcode, scanning, feedback, error } }
  const [sessionScans,  setSessionScans]  = useState({}); // { [did]: [{ scan_id, barcode, quantity }] }
  const [scannedTotals, setScannedTotals] = useState({}); // { [did]: total_scanned }

  const inputRefs = useRef({});

  const fetchIst = useCallback(() => {
    setLoading(true);
    setError('');
    istAPI.getById(id)
      .then(async (r) => {
        const data = r?.data ?? r;
        setIst(data);

        const uiInit = {};
        const totalsInit = {};
        (data?.details || []).forEach((d) => {
          uiInit[d.transfer_detail_id] = initDetailState();
          totalsInit[d.transfer_detail_id] = d.quantity_scanned ?? 0;
        });
        setDetailUi(uiInit);
        setScannedTotals(totalsInit);

        // Restore previously-recorded scans so they remain removable across refreshes.
        try {
          const st = await istAPI.scanStatus(id);
          const stData = st?.data ?? st;
          const history = stData?.scan_history || [];
          // Map product_id -> transfer_detail_id (first matching line)
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

  const handleScan = async (detail) => {
    const did = detail.transfer_detail_id;
    const ui  = detailUi[did] || initDetailState();
    const barcode = ui.barcode.trim();

    if (!barcode) {
      setDetailUi((prev) => ({ ...prev, [did]: { ...prev[did], error: 'Enter a barcode or serial number.' } }));
      return;
    }

    setDetailUi((prev) => ({ ...prev, [did]: { ...prev[did], scanning: true, error: null, feedback: null } }));

    try {
      const result = await istAPI.scan(id, { barcode, transfer_detail_id: did, quantity: 1 });
      const data = result?.data ?? result;
      const scanId      = data?.scan_id;
      const totalForDid = data?.total_scanned ?? ((scannedTotals[did] || 0) + 1);
      const remaining   = data?.remaining ?? 0;

      setSessionScans((prev) => {
        const existing = prev[did] || [];
        return { ...prev, [did]: [...existing, { scan_id: scanId, barcode, quantity: 1 }] };
      });
      setScannedTotals((prev) => ({ ...prev, [did]: totalForDid }));
      setDetailUi((prev) => ({
        ...prev,
        [did]: {
          ...initDetailState(),
          feedback: remaining > 0 ? `Recorded. ${remaining} unit(s) remaining.` : 'All requested units scanned.',
        },
      }));
      setTimeout(() => { inputRefs.current[did]?.focus(); }, 50);
    } catch (err) {
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : (err?.message || 'Scan failed.');
      setDetailUi((prev) => ({ ...prev, [did]: { ...prev[did], scanning: false, error: msg } }));
    }
  };

  const handleRemoveScan = async (did, scan) => {
    try {
      await istAPI.removeScan(id, scan.scan_id);
      setSessionScans((prev) => {
        const existing = prev[did] || [];
        return { ...prev, [did]: existing.filter((s) => s.scan_id !== scan.scan_id) };
      });
      setScannedTotals((prev) => ({ ...prev, [did]: Math.max(0, (prev[did] || 0) - (scan.quantity || 1)) }));
      setDetailUi((prev) => ({ ...prev, [did]: { ...(prev[did] || initDetailState()), feedback: 'Scan removed.' } }));
    } catch (err) {
      setDetailUi((prev) => ({ ...prev, [did]: { ...(prev[did] || initDetailState()), error: err?.message || 'Failed to remove scan.' } }));
    }
  };

  const handleGlobalScan = useCallback(async (code) => {
    const barcode = String(code || '').trim();
    if (!barcode) return;
    try {
      const result = await istAPI.scan(id, { barcode });
      const data = result?.data ?? result;
      const did  = data.transfer_detail_id;
      if (did == null) { toast.error('Scan not recorded', 'Unexpected response.'); return; }
      setSessionScans((prev) => ({
        ...prev,
        [did]: [...(prev[did] || []), { scan_id: data.scan_id, barcode, quantity: 1 }],
      }));
      setScannedTotals((prev) => ({ ...prev, [did]: data.total_scanned }));
      setDetailUi((prev) => ({
        ...prev,
        [did]: { ...initDetailState(), feedback: data.remaining > 0 ? `Recorded. ${data.remaining} remaining.` : 'All units scanned.' },
      }));
      toast.success(`Scanned ${data.product_name}`);
    } catch (err) {
      toast.error('Scan rejected', err?.message || 'Scan rejected.');
    }
  }, [id]);

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
                <p className="po-page-subtitle">Scan or enter each unit leaving the source, then confirm dispatch</p>
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

            <div className="po-details-card" style={{ marginBottom: 16, background: '#eff6ff', border: '1px solid #bfdbfe' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                <span style={{ fontWeight: 600, color: '#1d4ed8', fontSize: 14 }}>
                  Total scanned this session: {totalScanned}
                </span>
                <span style={{ fontSize: 12, color: '#64748b' }}>
                  {totalScanned < 1 ? 'Scan units below to record dispatch' : 'Ready to confirm when done (partial allowed)'}
                </span>
              </div>
            </div>

            <div className="po-details-card" style={{ marginBottom: 16 }}>
              <CameraScanner
                onDecode={handleGlobalScan}
                mountId="ist-dispatch-scanner"
                storageKey="bs_inventory_camera_device_id"
                busy={submitting}
                hint="Scan each unit leaving the source. It will be matched to its product automatically."
              />
            </div>

            {(ist.details || []).map((detail) => {
              const did       = detail.transfer_detail_id;
              const ui        = detailUi[did] || initDetailState();
              const target    = detail.quantity_requested ?? 0;
              const scanned   = scannedTotals[did] ?? 0;
              const remaining = Math.max(0, target - scanned);
              const complete  = remaining === 0 && target > 0;
              const scans     = sessionScans[did] || [];
              const isSerialized = detail.is_serialized || (detail.product?.is_serialized);
              const name      = detail.product?.product_name || `Product #${detail.product_id}`;
              const model     = detail.product?.model_name || detail.product?.model?.model_name || '';

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
                  <div className="po-line-items-header" style={{ borderBottom: '1px solid #f1f5f9', paddingBottom: 10, marginBottom: 12 }}>
                    <div style={{ flex: 1 }}>
                      <span className="po-line-items-title" style={{ display: 'block', marginBottom: 4 }}>
                        <span style={{ fontWeight: 700, fontSize: 14, color: '#1e293b' }}>{name}</span>
                        {model && <span style={{ fontSize: 11, color: '#94a3b8', marginLeft: 8 }}>{model}</span>}
                        {detail.product?.product_code && (
                          <span style={{ fontSize: 11, color: '#94a3b8', marginLeft: 8 }}>SKU: {detail.product.product_code}</span>
                        )}
                      </span>
                      <span style={{ fontSize: 12, color: '#64748b' }}>
                        Requested: <strong>{target}</strong>
                        &nbsp;|&nbsp;
                        Scanned: <strong style={{ color: complete ? '#16a34a' : '#1e293b' }}>{scanned}</strong>
                        &nbsp;|&nbsp;
                        Remaining: <strong style={{ color: remaining > 0 ? '#b45309' : '#16a34a' }}>{remaining}</strong>
                      </span>
                    </div>
                    {complete && (
                      <span style={{
                        background: '#dcfce7', color: '#16a34a', border: '1px solid #86efac',
                        borderRadius: 12, fontSize: 10, fontWeight: 700, padding: '3px 10px', letterSpacing: '0.5px',
                      }}>
                        COMPLETE
                      </span>
                    )}
                  </div>

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
                            if (e.key === 'Enter') { e.preventDefault(); handleScan(detail); }
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

                  {scans.length > 0 && (
                    <div style={{ marginTop: 10, borderTop: '1px solid #f1f5f9', paddingTop: 8 }}>
                      <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>SCANNED THIS SESSION:</span>
                      <div style={{ marginTop: 6, display: 'flex', flexDirection: 'column', gap: 4 }}>
                        {scans.map((s) => (
                          <div key={s.scan_id ?? s.barcode} style={{
                            display: 'flex', alignItems: 'center', gap: 8,
                            background: '#f8fafc', borderRadius: 4, padding: '4px 8px',
                          }}>
                            <span style={{ fontFamily: 'monospace', fontSize: 12, color: '#1e293b', flex: 1 }}>{s.barcode}</span>
                            <span style={{ fontSize: 11, color: '#64748b' }}>×{s.quantity}</span>
                            {s.scan_id != null && (
                              <button
                                type="button"
                                onClick={() => handleRemoveScan(did, s)}
                                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#dc2626', fontSize: 11, padding: '2px 6px', borderRadius: 3 }}
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

            <div className="po-details-card" style={{ marginBottom: 16 }}>
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
