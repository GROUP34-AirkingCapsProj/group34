import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { istAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
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

const ISTReceivePage = () => {
  const { id }   = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();

  const [ist,          setIst]          = useState(null);
  const [loading,      setLoading]      = useState(true);
  const [error,        setError]        = useState('');
  const [submitting,   setSubmitting]   = useState(false);
  const [submitError,  setSubmitError]  = useState('');

  // Form state
  const [receivedDate, setReceivedDate] = useState(todayIso());
  const [notes,        setNotes]        = useState('');
  const [quantities,   setQuantities]   = useState({}); // { [transfer_detail_id]: qty }

  const fetchIst = useCallback(() => {
    setLoading(true);
    setError('');
    istAPI.receiveForm(id)
      .then((r) => {
        const data = r?.data ?? r;
        setIst(data);
        // Pre-fill quantities with quantity_transferred (what was dispatched)
        const init = {};
        (data?.details || []).forEach((d) => {
          init[d.transfer_detail_id] = d.quantity_transferred ?? d.quantity_scanned ?? 0;
        });
        setQuantities(init);
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

  const handleQtyChange = (detailId, value) => {
    const parsed = Math.max(0, parseInt(value) || 0);
    setQuantities((prev) => ({ ...prev, [detailId]: parsed }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitError('');

    const products = (ist?.details || []).map((d) => ({
      transfer_detail_id: d.transfer_detail_id,
      quantity_received:  quantities[d.transfer_detail_id] ?? 0,
    }));

    const totalReceiving = products.reduce((s, p) => s + p.quantity_received, 0);
    if (totalReceiving < 1) {
      setSubmitError('Enter a received quantity for at least one product.');
      return;
    }

    setSubmitting(true);
    try {
      await istAPI.receive(id, { received_date: receivedDate, notes, products });
      navigate('/admin/ist', {
        state: { success: `${ist.transfer_number} has been received successfully.` },
      });
    } catch (err) {
      setSubmitError(err?.message || 'Failed to confirm receipt.');
    } finally {
      setSubmitting(false);
    }
  };

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
                  {loading ? 'Loading…' : `CONFIRM RECEIPT — ${ist?.transfer_number || ''}`}
                </h1>
                <p className="po-page-subtitle">Confirm items received at destination</p>
              </div>
            </div>
          </div>
        </div>

        {loading && <div className="po-status-loading">Loading IST…</div>}
        {error   && (
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
          <form onSubmit={handleSubmit}>

            {/* ── IST Header (read-only) ── */}
            <div className="po-details-card" style={{ marginBottom: 16 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '16px 24px' }}>
                {[
                  { label: 'IST NUMBER',     value: ist.transfer_number,          bold: true },
                  { label: 'FROM',           value: from,                         bold: true },
                  { label: 'TO',             value: to,                           bold: true },
                  { label: 'TRANSFER DATE',  value: fmtDate(ist.transfer_date) },
                  { label: 'DISPATCHED BY',  value: userName(dispatchEntry?.recordedBy) },
                  { label: 'DISPATCHED DATE',value: fmtDateTime(dispatchEntry?.recorded_at) },
                ].map(({ label, value, bold }) => (
                  <div key={label} className="po-field">
                    <span className="po-field-label">{label}</span>
                    <span style={{ fontSize: 14, color: '#1e293b', fontWeight: bold ? 700 : 500 }}>{value}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* ── Products table ── */}
            <div className="po-line-items-card" style={{ marginBottom: 16 }}>
              <div className="po-line-items-header">
                <span className="po-line-items-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2" width="16" height="16">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                  </svg>
                  ITEMS TO RECEIVE
                </span>
                <span style={{ fontSize: 12, color: '#94a3b8' }}>
                  Adjust quantities if not all items arrived
                </span>
              </div>
              <div className="po-table-wrapper">
                <table className="po-table">
                  <thead>
                    <tr>
                      <th>PRODUCT</th>
                      <th style={{ textAlign: 'center' }}>QTY DISPATCHED</th>
                      <th style={{ textAlign: 'center', minWidth: 140 }}>QTY RECEIVED</th>
                      <th>SERIALS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(ist.details || []).map((d) => {
                      const name     = d.product?.product_name || `Product #${d.product_id}`;
                      const model    = d.product?.model_name || d.product?.model?.model_name || '';
                      const dispatched = d.quantity_transferred ?? d.quantity_scanned ?? 0;
                      const received   = quantities[d.transfer_detail_id] ?? 0;
                      const over       = received > dispatched;
                      const serials    = Array.isArray(d.item_serials) ? d.item_serials : [];

                      return (
                        <tr key={d.transfer_detail_id}>
                          <td>
                            <div style={{ fontWeight: 600 }}>{name}</div>
                            {model && <div style={{ fontSize: 11, color: '#94a3b8' }}>{model}</div>}
                            {d.product?.product_code && (
                              <div style={{ fontSize: 11, color: '#94a3b8' }}>SKU: {d.product.product_code}</div>
                            )}
                          </td>
                          <td style={{ textAlign: 'center', fontWeight: 600 }}>{dispatched}</td>
                          <td style={{ textAlign: 'center' }}>
                            <input
                              type="number"
                              min={0}
                              max={dispatched}
                              value={received}
                              onChange={(e) => handleQtyChange(d.transfer_detail_id, e.target.value)}
                              style={{
                                width: 90, textAlign: 'center', padding: '5px 8px',
                                border: `1px solid ${over ? '#dc2626' : '#d1d5db'}`,
                                borderRadius: 6, fontSize: 14, fontWeight: 600,
                                color: over ? '#dc2626' : '#1e293b',
                                outline: 'none',
                              }}
                            />
                            {over && (
                              <div style={{ fontSize: 10, color: '#dc2626', marginTop: 2 }}>
                                Exceeds dispatched qty
                              </div>
                            )}
                          </td>
                          <td>
                            {serials.length === 0 ? (
                              <span style={{ color: '#94a3b8', fontSize: 12 }}>—</span>
                            ) : (
                              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                                {serials.map((sn) => (
                                  <span key={sn} style={{
                                    fontSize: 11, padding: '2px 6px', borderRadius: 4,
                                    background: '#f1f5f9', color: '#475569', fontFamily: 'monospace',
                                  }}>{sn}</span>
                                ))}
                              </div>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* ── Footer fields ── */}
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
              <div className="po-field" style={{ marginTop: 16 }}>
                <label htmlFor="ist-notes" className="po-field-label">REMARKS (optional)</label>
                <textarea
                  id="ist-notes"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  maxLength={500}
                  rows={3}
                  placeholder="Any notes about the receipt condition, discrepancies, etc."
                  style={{
                    width: '100%', padding: '8px 12px', borderRadius: 6, border: '1px solid #d1d5db',
                    fontSize: 13, color: '#1e293b', resize: 'vertical', boxSizing: 'border-box',
                  }}
                />
              </div>
            </div>

            {/* ── Submit ── */}
            {submitError && (
              <div className="po-submit-error" style={{ marginBottom: 12 }}>{submitError}</div>
            )}
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
                type="submit"
                className="po-btn"
                style={{ background: '#1e293b', color: '#fff', minWidth: 180 }}
                disabled={submitting}
              >
                {submitting ? 'Confirming…' : 'Confirm Receipt'}
              </button>
            </div>

          </form>
        )}
      </div>
    </AdminLayout>
  );
};

export default ISTReceivePage;
