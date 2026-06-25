import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { istAPI } from '../services/api';
import { useAuth, useUserLocation } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/po_recommendation.css';
import '../styles/dashboard_air.css';

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

const STATUS_CFG = {
  'Approved':           { label: 'APPROVED',           color: '#16a34a', bg: '#dcfce7' },
  'In Transit':         { label: 'IN TRANSIT',         color: '#2563eb', bg: '#dbeafe' },
  'Partially Received': { label: 'PARTIALLY RECEIVED', color: '#d97706', bg: '#fef3c7' },
  'Received':           { label: 'RECEIVED',           color: '#16a34a', bg: '#dcfce7' },
  'Rejected':           { label: 'REJECTED',           color: '#dc2626', bg: '#fee2e2' },
};

const StatusBadge = ({ name }) => {
  const cfg = STATUS_CFG[name] || { label: name || 'UNKNOWN', color: '#64748b', bg: '#f1f5f9' };
  return (
    <span style={{
      display: 'inline-block', padding: '3px 10px', borderRadius: 20, fontSize: 11,
      fontWeight: 700, letterSpacing: 0.5, color: cfg.color, background: cfg.bg,
    }}>
      {cfg.label}
    </span>
  );
};

const TrackingIcon = ({ note }) => {
  if (!note) return null;
  const n = note.toLowerCase();
  if (n.includes('dispatched') || n.includes('in transit'))
    return (
      <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" strokeWidth="2" width="18" height="18">
        <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
        <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
      </svg>
    );
  if (n.includes('approved'))
    return (
      <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2" width="18" height="18">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    );
  if (n.includes('created'))
    return (
      <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2" width="18" height="18">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
    );
  if (n.includes('received'))
    return (
      <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2" width="18" height="18">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
      </svg>
    );
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="2" width="18" height="18">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
    </svg>
  );
};

const ISTDetailPage = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { locationId } = useUserLocation();
  const roleKey = getRoleKey(user);
  const isAdmin = roleKey === ROLES.ADMIN || roleKey === ROLES.INVENTORY_ANALYST;

  const [ist,     setIst]     = useState(null);
  const [loading, setLoading] = useState(true);
  const [error,   setError]   = useState('');

  const fetchIst = useCallback(() => {
    setLoading(true);
    setError('');
    istAPI.getById(id)
      .then((r) => setIst(r?.data ?? r))
      .catch((e) => setError(e?.message || 'Failed to load IST.'))
      .finally(() => setLoading(false));
  }, [id]);

  useEffect(() => { fetchIst(); }, [fetchIst]);

  const statusName = ist?.status?.status_name || '';
  const from = ist?.from_location?.location_name || '—';
  const to   = ist?.to_location?.location_name   || '—';

  // Dispatch entry from tracking
  const dispatchEntry = ist?.transfer_tracking
    ?.filter((t) => (t.status_note || '').toLowerCase().includes('dispatched'))
    .sort((a, b) => new Date(b.recorded_at) - new Date(a.recorded_at))[0];

  // Receive entry from tracking
  const receiveEntry = ist?.transfer_tracking
    ?.filter((t) => (t.status_note || '').toLowerCase().includes('received'))
    .sort((a, b) => new Date(b.recorded_at) - new Date(a.recorded_at))[0];

  // Tracking sorted ASC for timeline
  const timeline = [...(ist?.transfer_tracking || [])].sort(
    (a, b) => new Date(a.recorded_at) - new Date(b.recorded_at)
  );

  // Show "Confirm Receipt" when In Transit and user is at destination (or admin)
  const canReceive = ist && statusName === 'In Transit' &&
    (isAdmin || String(locationId || '') === String(ist.to_location_id || ''));

  // Show receipt buttons only when status = Received
  const isReceived = statusName === 'Received';

  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page">

        {/* Back + Title */}
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <button
                type="button"
                className="po-btn"
                style={{ padding: '6px 12px', fontSize: 12, marginRight: 12, flexShrink: 0 }}
                onClick={() => navigate('/admin/ist')}
              >
                ← Back
              </button>
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
                  strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
                  <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">
                  {loading ? 'Loading…' : (ist?.transfer_number || `IST #${id}`)}
                </h1>
                <p className="po-page-subtitle">Interbranch Stock Transfer Detail</p>
              </div>
            </div>
            {ist && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <StatusBadge name={statusName} />
                {statusName === 'Approved' && (
                  <button
                    type="button"
                    className="po-btn"
                    style={{ background: '#1e293b', color: '#fff', fontSize: 12, padding: '8px 16px', whiteSpace: 'nowrap' }}
                    onClick={() => navigate(`/admin/ist/${id}/dispatch`)}
                  >
                    Scan / Dispatch
                  </button>
                )}
                {canReceive && (
                  <button
                    type="button"
                    className="po-btn"
                    style={{ background: '#1e293b', color: '#fff', fontSize: 12, padding: '8px 16px', whiteSpace: 'nowrap' }}
                    onClick={() => navigate(`/admin/ist/${id}/receive`)}
                  >
                    Confirm Receipt
                  </button>
                )}
                {isReceived && (
                  <>
                    <button
                      type="button"
                      className="po-btn"
                      style={{ background: '#1e293b', color: '#fff', fontSize: 12, padding: '8px 16px', whiteSpace: 'nowrap' }}
                      onClick={() => navigate(`/admin/ist/${id}/receipt`)}
                    >
                      Print Receipt
                    </button>
                    <a
                      href={istAPI.receiptPdfUrl(id)}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="po-btn"
                      style={{ background: '#f59e0b', color: '#fff', fontSize: 12, padding: '8px 16px', whiteSpace: 'nowrap', textDecoration: 'none' }}
                    >
                      Download PDF
                    </a>
                  </>
                )}
              </div>
            )}
          </div>
        </div>

        {loading && <Loader label="Loading IST" />}
        {error   && <div className="po-submit-error">{error}</div>}

        {ist && !loading && (
          <>
            {/* ── Header Info ── */}
            <div className="po-details-card" style={{ marginBottom: 16 }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '16px 24px' }}>
                {[
                  { label: 'IST NUMBER',       value: ist.transfer_number,                    bold: true },
                  { label: 'FROM',              value: from,                                   bold: true },
                  { label: 'TO',                value: to,                                     bold: true },
                  { label: 'TRANSFER DATE',     value: fmtDate(ist.transfer_date) },
                  { label: 'PREPARED BY',       value: userName(ist.prepared_by) },
                  { label: 'APPROVED BY',       value: userName(ist.approved_by) },
                  ...(ist.transfer_request?.transfer_number
                    ? [{ label: 'FROM REQUEST', value: ist.transfer_request.transfer_number }]
                    : []),
                  ...(dispatchEntry
                    ? [
                        { label: 'DISPATCHED DATE', value: fmtDateTime(dispatchEntry.recorded_at) },
                        { label: 'DISPATCHED BY',   value: userName(dispatchEntry.recorded_by) },
                      ]
                    : []),
                  ...(receiveEntry || ist.received_by
                    ? [
                        { label: 'RECEIVED DATE', value: fmtDateTime(receiveEntry?.recorded_at) },
                        { label: 'RECEIVED BY',   value: userName(ist.received_by || receiveEntry?.recorded_by) },
                      ]
                    : []),
                  { label: 'TOTAL DISPATCHED', value: `${ist.total_quantity_transferred ?? 0} unit(s)` },
                  { label: 'TOTAL RECEIVED',   value: `${ist.total_quantity_received ?? 0} unit(s)` },
                ].map(({ label, value, bold }) => (
                  <div key={label} className="po-field">
                    <span className="po-field-label">{label}</span>
                    <span style={{ fontSize: 14, color: '#1e293b', fontWeight: bold ? 700 : 500 }}>{value}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* ── Products Table ── */}
            <div className="po-line-items-card" style={{ marginBottom: 16 }}>
              <div className="po-line-items-header">
                <span className="po-line-items-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2" width="16" height="16">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
                  </svg>
                  PRODUCTS
                </span>
              </div>
              {(!ist.details || ist.details.length === 0) ? (
                <div className="po-status-empty" style={{ padding: 24 }}>No line items.</div>
              ) : (
                <div className="po-table-wrapper">
                  <table className="po-table">
                    <thead>
                      <tr>
                        <th>PRODUCT</th>
                        <th style={{ textAlign: 'center' }}>QTY EXPECTED</th>
                        <th style={{ textAlign: 'center' }}>QTY DISPATCHED</th>
                        <th style={{ textAlign: 'center' }}>QTY RECEIVED</th>
                        <th>SERIAL NUMBERS</th>
                      </tr>
                    </thead>
                    <tbody>
                      {ist.details.map((d) => {
                        const name   = d.product?.product_name || `Product #${d.product_id}`;
                        const model  = d.product?.model_code || d.product?.applianceDetail?.model_code || '—';
                        const serials = Array.isArray(d.item_serials) ? d.item_serials : [];
                        return (
                          <tr key={d.transfer_detail_id}>
                            <td>
                              <div style={{ fontWeight: 600 }}>{name}</div>
                              {model !== '—' && <div style={{ fontSize: 11, color: '#94a3b8' }}>{model}</div>}
                              {d.product?.product_code && (
                                <div style={{ fontSize: 11, color: '#94a3b8' }}>SKU: {d.product.product_code}</div>
                              )}
                            </td>
                            <td style={{ textAlign: 'center' }}>{d.quantity_requested ?? 0}</td>
                            <td style={{ textAlign: 'center' }}>
                              <span style={{ fontWeight: 600, color: (d.quantity_transferred || 0) > 0 ? '#16a34a' : '#64748b' }}>
                                {d.quantity_transferred ?? 0}
                              </span>
                            </td>
                            <td style={{ textAlign: 'center' }}>{d.quantity_received ?? 0}</td>
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
              )}
            </div>

            {/* ── Audit Timeline ── */}
            <div className="po-line-items-card">
              <div className="po-line-items-header">
                <span className="po-line-items-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="2" width="16" height="16">
                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                  </svg>
                  AUDIT TIMELINE
                </span>
              </div>
              {timeline.length === 0 ? (
                <div className="po-status-empty" style={{ padding: 24 }}>No tracking entries yet.</div>
              ) : (
                <div style={{ padding: '8px 0' }}>
                  {timeline.map((entry, idx) => (
                    <div key={entry.tracking_id ?? idx} style={{
                      display: 'flex', gap: 14, padding: '10px 16px',
                      borderBottom: idx < timeline.length - 1 ? '1px solid #f1f5f9' : 'none',
                      alignItems: 'flex-start',
                    }}>
                      <div style={{
                        width: 36, height: 36, borderRadius: '50%', flexShrink: 0,
                        background: '#f8fafc', border: '1px solid #e2e8f0',
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                      }}>
                        <TrackingIcon note={entry.status_note} />
                      </div>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontSize: 13, fontWeight: 600, color: '#1e293b', marginBottom: 2 }}>
                          {entry.status_note || '—'}
                        </div>
                        <div style={{ display: 'flex', gap: 16, fontSize: 12, color: '#64748b' }}>
                          <span>{fmtDateTime(entry.recorded_at)}</span>
                          {entry.recorded_by && (
                            <span>by <strong style={{ color: '#475569' }}>{userName(entry.recorded_by)}</strong></span>
                          )}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default ISTDetailPage;
