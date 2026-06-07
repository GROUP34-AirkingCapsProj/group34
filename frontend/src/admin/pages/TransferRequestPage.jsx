import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { transferRequestsAPI, locationsAPI, productsAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import '../styles/archive_management.css';
import DateInput from '../components/DateInput';

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS = { PENDING: 'Pending', APPROVED: 'Approved', REJECTED: 'Rejected' };

const statusCfg = (name) => {
  switch (name) {
    case STATUS.PENDING:  return { label: 'PENDING APPROVAL', color: 'pending'  };
    case STATUS.APPROVED: return { label: 'APPROVED',          color: 'approved' };
    case STATUS.REJECTED: return { label: 'REJECTED',          color: 'rejected' };
    default:              return { label: name || 'UNKNOWN',   color: 'draft'    };
  }
};

const today = () => new Date().toISOString().slice(0, 10);
const newLine = () => ({ id: `ln-${Date.now()}-${Math.random().toString(36).slice(2)}`, product_id: '', qty: 1 });

const fmtDate = (v) => (v ? String(v).slice(0, 10) : '—');
const fmtDT   = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleString('en-PH', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const StatusBadge = ({ name }) => {
  const c = statusCfg(name);
  return <span className={`po-status-badge po-status-badge--${c.color}`}>{c.label}</span>;
};

// ─── Main ─────────────────────────────────────────────────────────────────────

const TransferRequestPage = () => {
  const { user }    = useAuth();
  const navigate    = useNavigate();
  const roleKey     = getRoleKey(user);
  const isAdmin     = roleKey === ROLES.ADMIN || roleKey === ROLES.INVENTORY_ANALYST;
  const canCreateIST = isAdmin || roleKey === ROLES.WAREHOUSE_PERSONNEL;
  const userLocId   = String(user?.enforced_location_id ?? user?.location_id ?? '');

  const [activeTab, setActiveTab] = useState(isAdmin ? 'approvals' : 'create');

  // ── Shared dropdowns ──────────────────────────────────────────────────────
  const [locations, setLocations] = useState([]);
  const [products,  setProducts]  = useState([]);
  const [loadingLoc,  setLoadingLoc]  = useState(true);
  const [loadingProd, setLoadingProd] = useState(true);

  useEffect(() => {
    locationsAPI.getAll({ per_page: 500 })
      .then(r => setLocations(Array.isArray(r?.data) ? r.data : []))
      .catch(console.error).finally(() => setLoadingLoc(false));
  }, []);

  useEffect(() => {
    productsAPI.getAll({ per_page: 1000 })
      .then(r => setProducts(Array.isArray(r?.data) ? r.data : []))
      .catch(console.error).finally(() => setLoadingProd(false));
  }, []);

  // ── Tab 1 — Create ────────────────────────────────────────────────────────
  const [fromLocId,    setFromLocId]    = useState('');
  const [toLocId,      setToLocId]      = useState(userLocId);
  const [txDate,       setTxDate]       = useState(today());
  const [notes,        setNotes]        = useState('');
  const [lines,        setLines]        = useState([newLine()]);
  const [submitting,   setSubmitting]   = useState(false);
  const [submitErr,    setSubmitErr]    = useState('');
  const [submitOk,     setSubmitOk]     = useState(false);
  const [createdNum,   setCreatedNum]   = useState('');

  const addLine    = useCallback(() => setLines(p => [...p, newLine()]), []);
  const removeLine = useCallback((id) => setLines(p => p.filter(l => l.id !== id)), []);
  const updateLine = useCallback((id, f, v) => setLines(p => p.map(l => l.id === id ? { ...l, [f]: v } : l)), []);

  const handleSubmit = useCallback(async () => {
    setSubmitErr('');
    if (!fromLocId)             { setSubmitErr('Select a source location.'); return; }
    if (!toLocId)               { setSubmitErr('Select a destination.'); return; }
    if (fromLocId === toLocId)  { setSubmitErr('From and To must be different.'); return; }
    if (!txDate)                { setSubmitErr('Transfer date required.'); return; }
    const valid = lines.filter(l => l.product_id && l.qty > 0);
    if (!valid.length)          { setSubmitErr('Add at least one product.'); return; }

    setSubmitting(true);
    try {
      const res = await transferRequestsAPI.create({
        from_location_id: Number(fromLocId),
        to_location_id:   Number(toLocId),
        transfer_date:    txDate,
        notes:            notes.trim() || null,
        products: valid.map(l => ({
          product_id:         Number(l.product_id),
          quantity_requested: Math.max(1, parseInt(l.qty) || 1),
        })),
      });
      setCreatedNum(res?.data?.transfer_number || 'Transfer Request');
      setSubmitOk(true);
      setLines([newLine()]);
      setNotes('');
      setFromLocId('');
    } catch (err) {
      setSubmitErr(err?.errors ? Object.values(err.errors).flat().join(' ') : err?.message || 'Failed to submit.');
    } finally {
      setSubmitting(false);
    }
  }, [fromLocId, toLocId, txDate, notes, lines]);

  // ── Tab 2 — My Requests ───────────────────────────────────────────────────
  const [myReqs,     setMyReqs]    = useState([]);
  const [loadingMine, setLoadingMine] = useState(false);

  useEffect(() => {
    if (activeTab !== 'my-requests') return;
    setLoadingMine(true);
    transferRequestsAPI.getAll({ per_page: 100 })
      .then(r => setMyReqs(Array.isArray(r?.data) ? r.data : []))
      .catch(console.error).finally(() => setLoadingMine(false));
  }, [activeTab]);

  // ── Tab 3 — Pending Approvals ─────────────────────────────────────────────
  const [pendingReqs, setPendingReqs]   = useState([]);
  const [loadingPend, setLoadingPend]   = useState(false);

  const fetchPending = useCallback(() => {
    setLoadingPend(true);
    transferRequestsAPI.getAll({ per_page: 100 })
      .then(r => {
        const all = Array.isArray(r?.data) ? r.data : [];
        setPendingReqs(all.filter(x => x.status?.status_name === STATUS.PENDING));
      })
      .catch(console.error).finally(() => setLoadingPend(false));
  }, []);

  useEffect(() => {
    if (activeTab === 'approvals' && isAdmin) fetchPending();
  }, [activeTab, isAdmin, fetchPending]);

  // ── Detail modal (shared: view + approval) ────────────────────────────────
  const [detailReq,    setDetailReq]    = useState(null);   // full record from show()
  const [loadingDetail, setLoadingDetail] = useState(false);
  // Editable approved quantities: { [transfer_detail_id]: qty }
  const [approvedQtys, setApprovedQtys] = useState({});
  const [actionLoading, setActionLoading] = useState({});

  const openDetail = useCallback(async (r) => {
    setDetailReq(r);   // show modal immediately with card data
    setApprovedQtys({}); // clear stale state
    setLoadingDetail(true);
    try {
      const res = await transferRequestsAPI.getById(r.transfer_id);
      const full = res?.data ?? r;
      setDetailReq(full);
      // Initialise editable quantities to quantity_requested
      const init = {};
      (full.details ?? []).forEach(d => { init[d.transfer_detail_id] = d.quantity_requested; });
      setApprovedQtys(init);
    } catch (_) { /* keep card-level data */ }
    finally { setLoadingDetail(false); }
  }, []);

  const closeDetail = useCallback(() => { setDetailReq(null); setApprovedQtys({}); }, []);

  // ── Approve ───────────────────────────────────────────────────────────────
  const handleApprove = useCallback(async (id) => {
    setActionLoading(p => ({ ...p, [id]: 'approving' }));
    try {
      const quantities = Object.entries(approvedQtys).map(([detailId, qty]) => ({
        transfer_detail_id: Number(detailId),
        quantity_approved:  Math.max(1, parseInt(qty) || 1),
      }));
      await transferRequestsAPI.approve(id, { quantities });
      setPendingReqs(p => p.filter(r => r.transfer_id !== id));
      closeDetail();
    } catch (err) { alert(err?.message || 'Approval failed.'); }
    finally { setActionLoading(p => { const n = { ...p }; delete n[id]; return n; }); }
  }, [approvedQtys, closeDetail]);

  // ── Reject ────────────────────────────────────────────────────────────────
  const [rejectModal, setRejectModal] = useState({ open: false, id: null, reason: '', submitting: false });
  const openReject  = useCallback((id) => setRejectModal({ open: true, id, reason: '', submitting: false }), []);
  const handleReject = useCallback(async () => {
    if (!rejectModal.reason.trim()) return;
    setRejectModal(p => ({ ...p, submitting: true }));
    try {
      await transferRequestsAPI.reject(rejectModal.id, { rejection_reason: rejectModal.reason });
      setPendingReqs(p => p.filter(r => r.transfer_id !== rejectModal.id));
      setRejectModal({ open: false, id: null, reason: '', submitting: false });
      closeDetail();
    } catch (err) {
      alert(err?.message || 'Rejection failed.');
      setRejectModal(p => ({ ...p, submitting: false }));
    }
  }, [rejectModal, closeDetail]);

  // ── Helpers ───────────────────────────────────────────────────────────────
  const locName = useCallback((id) => {
    const l = locations.find(x => String(x.location_id) === String(id));
    return l?.location_name || '—';
  }, [locations]);

  const reqLocNames = (r) => ({
    from: r.from_location?.location_name || r.fromLocation?.location_name || locName(r.from_location_id),
    to:   r.to_location?.location_name   || r.toLocation?.location_name   || locName(r.to_location_id),
  });

  const userName = (u) => u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER: Create tab
  // ─────────────────────────────────────────────────────────────────────────
  const renderCreate = () => (
    <div className="pr-create-form-layout">
      {submitOk && (
        <div className="po-success-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
          </svg>
          <strong>{createdNum}</strong> submitted — waiting for approval.
          <button type="button" className="po-success-close" onClick={() => setSubmitOk(false)} aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="14" height="14">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      )}

      <div className="po-details-card">
        <div className="pr-form-grid">
          <div className="po-field">
            <label className="po-field-label">FROM LOCATION (Source) *</label>
            <select className="po-select" value={fromLocId} onChange={e => setFromLocId(e.target.value)} disabled={loadingLoc}>
              <option value="">{loadingLoc ? 'Loading…' : 'Select source location'}</option>
              {locations.map(l => (
                <option key={l.location_id} value={String(l.location_id)} disabled={String(l.location_id) === toLocId}>
                  {l.location_name}{l.location_type ? ` (${l.location_type})` : ''}
                </option>
              ))}
            </select>
          </div>

          <div className="po-field">
            <label className="po-field-label">TO LOCATION (Destination) *</label>
            <select
              className="po-select"
              value={toLocId}
              onChange={e => setToLocId(e.target.value)}
              disabled={loadingLoc || (!isAdmin && !!userLocId)}
            >
              <option value="">{loadingLoc ? 'Loading…' : 'Select destination'}</option>
              {locations.map(l => (
                <option key={l.location_id} value={String(l.location_id)} disabled={String(l.location_id) === fromLocId}>
                  {l.location_name}{l.location_type ? ` (${l.location_type})` : ''}
                </option>
              ))}
            </select>
            {!isAdmin && userLocId && (
              <p style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>Auto-filled from your assigned location.</p>
            )}
          </div>

          <div className="po-field">
            <label className="po-field-label">TRANSFER DATE *</label>
            <DateInput className="po-input" value={txDate} onChange={e => setTxDate(e.target.value)} />
          </div>
        </div>

        <div className="po-field" style={{ marginTop: 16 }}>
          <label className="po-field-label">NOTES / REMARKS (optional)</label>
          <textarea
            className="po-input"
            style={{ minHeight: 64, resize: 'vertical', fontFamily: 'inherit', fontSize: 13 }}
            value={notes}
            onChange={e => setNotes(e.target.value)}
            placeholder="Reason for request, urgency, etc."
          />
        </div>
      </div>

      <div className="po-line-items-card">
        <div className="po-line-items-header">
          <div className="po-line-items-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
              <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
              <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>
            </svg>
            REQUESTED PRODUCTS
            <span className="po-line-items-count">{lines.length}</span>
          </div>
          <button type="button" className="po-btn po-btn-add-product" onClick={addLine}>+ ADD PRODUCT</button>
        </div>

        <div className="po-table-wrapper">
          <table className="po-table">
            <thead>
              <tr><th>#</th><th>PRODUCT</th><th style={{ width: 110 }}>QTY REQUESTED</th><th style={{ width: 40 }}></th></tr>
            </thead>
            <tbody>
              {lines.map((l, idx) => (
                <tr key={l.id}>
                  <td style={{ color: '#94a3b8', fontSize: 12 }}>{idx + 1}</td>
                  <td style={{ minWidth: 240 }}>
                    <select className="po-select" style={{ fontSize: 12 }} value={l.product_id} onChange={e => updateLine(l.id, 'product_id', e.target.value)} disabled={loadingProd}>
                      <option value="">{loadingProd ? 'Loading…' : 'Select product…'}</option>
                      {products.map(p => (
                        <option key={p.product_id} value={String(p.product_id)}>
                          {p.product_code ? `[${p.product_code}] ` : ''}{p.product_name}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td>
                    <input type="number" min="1" className="po-qty-input" value={l.qty} onChange={e => updateLine(l.id, 'qty', e.target.value)} />
                  </td>
                  <td>
                    {lines.length > 1 && (
                      <button type="button" className="po-remove-btn" onClick={() => removeLine(l.id)} title="Remove">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                          <polyline points="3 6 5 6 21 6"/>
                          <path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>
                        </svg>
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {submitErr && <div className="po-submit-error">{submitErr}</div>}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 8 }}>
        <button type="button" className="po-btn po-btn-final-submission" onClick={handleSubmit} disabled={submitting}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
            <path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>
          </svg>
          {submitting ? 'SUBMITTING…' : 'SUBMIT TRANSFER REQUEST'}
        </button>
      </div>
    </div>
  );

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER: My Requests tab
  // ─────────────────────────────────────────────────────────────────────────
  const renderMyRequests = () => {
    if (loadingMine) return <div className="po-status-loading">Loading requests…</div>;
    if (!myReqs.length) return (
      <div className="po-status-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="40" height="40">
          <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/><path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
        </svg>
        <p>No transfer requests yet.</p>
        <span>Use &quot;Create Request&quot; to submit your first one.</span>
      </div>
    );

    return (
      <div className="po-status-list">
        {myReqs.map(r => {
          const { from, to } = reqLocNames(r);
          const cfg = statusCfg(r.status?.status_name);
          return (
            <div key={r.transfer_id} className="po-status-card">
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{r.transfer_number}</span>
                    <span className={`po-status-badge po-status-badge--${cfg.color}`}>{cfg.label}</span>
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item"><span className="po-status-meta-label">FROM</span><span className="po-status-meta-value">{from}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">TO</span><span className="po-status-meta-value">{to}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">DATE</span><span className="po-status-meta-value">{fmtDate(r.transfer_date)}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">PRODUCTS</span><span className="po-status-meta-value">{r.details?.length ?? 0} item(s)</span></div>
                    {r.notes && (
                      <div className="po-status-meta-item" style={{ gridColumn: '1 / -1' }}>
                        <span className="po-status-meta-label">NOTES</span>
                        <span className="po-status-meta-value" style={{ fontStyle: 'italic' }}>{r.notes}</span>
                      </div>
                    )}
                  </div>
                </div>
                <div className="po-status-card-right" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  <button type="button" className="po-status-view-btn" onClick={() => openDetail(r)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    VIEW
                  </button>
                  {r.status?.status_name === STATUS.APPROVED && canCreateIST && (
                    <button
                      type="button"
                      className="po-btn"
                      style={{ fontSize: 12, padding: '6px 12px', whiteSpace: 'nowrap' }}
                      onClick={() => navigate(`/admin/ist/create/${r.transfer_id}`)}
                    >
                      + CREATE IST
                    </button>
                  )}
                </div>
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER: Pending Approvals tab
  // ─────────────────────────────────────────────────────────────────────────
  const renderApprovals = () => {
    if (loadingPend) return <div className="po-status-loading">Loading pending approvals…</div>;
    if (!pendingReqs.length) return (
      <div className="po-status-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="40" height="40">
          <polyline points="9 11 12 14 22 4"/>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        <p>No pending approvals.</p>
      </div>
    );

    return (
      <div className="po-status-list">
        {pendingReqs.map(r => {
          const { from, to } = reqLocNames(r);
          const itemCount = r.details?.length ?? 0;
          return (
            <div key={r.transfer_id} className="po-status-card">
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{r.transfer_number}</span>
                    <span className="po-status-badge po-status-badge--pending">PENDING APPROVAL</span>
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item"><span className="po-status-meta-label">REQUESTED BY</span><span className="po-status-meta-value">{userName(r.requestedBy)}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">FROM</span><span className="po-status-meta-value">{from}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">TO</span><span className="po-status-meta-value">{to}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">DATE</span><span className="po-status-meta-value">{fmtDate(r.transfer_date)}</span></div>
                    <div className="po-status-meta-item"><span className="po-status-meta-label">PRODUCTS</span><span className="po-status-meta-value">{itemCount}</span></div>
                  </div>
                  {r.notes && (
                    <div style={{ fontSize: 12, color: '#64748b', marginTop: 8, fontStyle: 'italic' }}>Notes: {r.notes}</div>
                  )}
                  <div className="pr-action-btns" style={{ marginTop: 12 }}>
                    <button type="button" className="po-status-view-btn" onClick={() => openDetail(r)}>
                      REVIEW &amp; APPROVE
                    </button>
                    <button type="button" className="pr-btn-reject" onClick={() => openReject(r.transfer_id)}>
                      REJECT
                    </button>
                  </div>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER: Detail / Approval modal
  // ─────────────────────────────────────────────────────────────────────────
  const renderDetailModal = () => {
    if (!detailReq) return null;
    const r         = detailReq;
    const cfg       = statusCfg(r.status?.status_name);
    const { from, to } = reqLocNames(r);
    const isPending = r.status?.status_name === STATUS.PENDING;
    const isApproving = actionLoading[r.transfer_id] === 'approving';
    const details   = Array.isArray(r.details) ? r.details : [];
    const tracking  = Array.isArray(r.transferTracking) ? [...r.transferTracking].sort((a, b) => new Date(a.recorded_at) - new Date(b.recorded_at)) : [];

    return (
      <Modal isOpen={!!detailReq} onClose={closeDetail} title="" hideHeader maxWidth={720}>
        <div className="po-detail-modal">
          {/* Header */}
          <div className="po-detail-head">
            <div className="po-detail-head-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="26" height="26">
                <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/><path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
              </svg>
            </div>
            <div className="po-detail-head-info">
              <h2 className="po-detail-head-number">{r.transfer_number}</h2>
              <p className="po-detail-head-sub">{from} → {to}</p>
            </div>
            <div className="po-detail-head-badges">
              <span className={`po-detail-status-pill po-detail-status-pill--${cfg.color}`}>{cfg.label}</span>
            </div>
            <button type="button" className="po-detail-head-close" onClick={closeDetail} aria-label="Close">×</button>
          </div>

          <div className="po-detail-body">
            {/* Info grid */}
            <div className="po-detail-info-grid">
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">REQUESTED BY</span>
                <span className="po-detail-info-value">{userName(r.requestedBy)}</span>
              </div>
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">TRANSFER DATE</span>
                <span className="po-detail-info-value">{fmtDate(r.transfer_date)}</span>
              </div>
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">FROM LOCATION</span>
                <span className="po-detail-info-value">{from}</span>
              </div>
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">TO LOCATION</span>
                <span className="po-detail-info-value">{to}</span>
              </div>
              {r.approvedBy && (
                <div className="po-detail-info-item">
                  <span className="po-detail-info-label">
                    {r.status?.status_name === STATUS.REJECTED ? 'REVIEWED BY' : 'APPROVED BY'}
                  </span>
                  <span className="po-detail-info-value">{userName(r.approvedBy)}</span>
                </div>
              )}
              {r.notes && (
                <div className="po-detail-info-item" style={{ gridColumn: '1 / -1' }}>
                  <span className="po-detail-info-label">NOTES</span>
                  <span className="po-detail-info-value" style={{ fontStyle: 'italic' }}>{r.notes}</span>
                </div>
              )}
            </div>

            {/* Products — editable when pending + admin */}
            <div className="po-detail-items-wrap" style={{ marginTop: 20 }}>
              <div className="po-detail-items-title-row">
                <span className="po-detail-items-heading">REQUESTED PRODUCTS</span>
                <span className="po-detail-items-count">{details.length}</span>
                {loadingDetail && (
                  <span style={{ fontSize: 11, color: '#94a3b8', marginLeft: 8 }}>Loading details…</span>
                )}
              </div>

              {isPending && isAdmin && (
                <p style={{ fontSize: 12, color: '#64748b', marginBottom: 8 }}>
                  Review quantities below. Adjust the <strong>Approved Qty</strong> column if needed before approving.
                </p>
              )}

              {details.length === 0 ? (
                <p className="po-detail-no-items">No items recorded.</p>
              ) : (
                <table className="po-detail-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>PRODUCT</th>
                      <th>REQUESTED</th>
                      <th>AVAIL AT SOURCE</th>
                      {isPending && isAdmin ? <th>APPROVED QTY</th> : <th>TRANSFERRED</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {details.map((d, di) => {
                      const stockOk = (d.stock_at_source ?? null) !== null;
                      const insufficient = stockOk && d.stock_at_source < d.quantity_requested;
                      const approvedVal = approvedQtys[d.transfer_detail_id] ?? d.quantity_requested;
                      return (
                        <tr key={d.transfer_detail_id ?? di} style={insufficient ? { background: '#fff7ed' } : {}}>
                          <td className="po-detail-table-idx">{di + 1}</td>
                          <td>
                            <div>{d.product?.product_name || '—'}</div>
                            {d.product?.product_code && <small style={{ color: '#94a3b8' }}>{d.product.product_code}</small>}
                          </td>
                          <td>
                            {d.quantity_requested}
                            {!loadingDetail && (
                              <span className="line-stock" style={{ display: 'block', fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                {'avail: '}
                                <span style={{ fontWeight: 600 }}>{d.available_at_source ?? d.stock_at_source ?? 0}</span>
                                {Number(d.shortfall) > 0 && (
                                  <span className="line-shortfall" style={{ color: '#b45309', fontWeight: 600, marginLeft: 4 }}>
                                    ⚠ short {d.shortfall}
                                  </span>
                                )}
                              </span>
                            )}
                          </td>
                          <td>
                            {loadingDetail ? (
                              <span style={{ color: '#94a3b8' }}>—</span>
                            ) : stockOk ? (
                              <span style={{ color: insufficient ? '#dc2626' : '#16a34a', fontWeight: 600 }}>
                                {d.stock_at_source}
                                {insufficient && (
                                  <span title="Insufficient stock at source" style={{ marginLeft: 4 }}>⚠</span>
                                )}
                              </span>
                            ) : '—'}
                          </td>
                          <td>
                            {isPending && isAdmin ? (
                              <input
                                type="number"
                                min="1"
                                max={d.stock_at_source ?? undefined}
                                className="po-qty-input"
                                value={approvedVal}
                                onChange={e =>
                                  setApprovedQtys(p => ({ ...p, [d.transfer_detail_id]: e.target.value }))
                                }
                                style={{ width: 80 }}
                              />
                            ) : (
                              <span style={{ color: d.quantity_transferred > 0 ? '#16a34a' : '#94a3b8' }}>
                                {d.quantity_transferred ?? 0}
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>

            {/* Tracking timeline */}
            {tracking.length > 0 && (
              <div style={{ marginTop: 24 }}>
                <div className="po-detail-items-title-row" style={{ marginBottom: 10 }}>
                  <span className="po-detail-items-heading">ACTIVITY TIMELINE</span>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {tracking.map((t, ti) => (
                    <div key={t.tracking_id ?? ti} style={{ display: 'flex', gap: 12, alignItems: 'flex-start', fontSize: 13 }}>
                      <div style={{
                        width: 8, height: 8, borderRadius: '50%', background: '#3b82f6',
                        flexShrink: 0, marginTop: 5,
                      }} />
                      <div>
                        <div style={{ color: '#1e293b', fontWeight: 500 }}>{t.status_note}</div>
                        <div style={{ color: '#94a3b8', fontSize: 11, marginTop: 2 }}>
                          {fmtDT(t.recorded_at)}
                          {t.recordedBy && ` · ${userName(t.recordedBy)}`}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Approve / Reject actions */}
            {isPending && isAdmin && (
              <div className="pr-modal-action-row" style={{ marginTop: 20 }}>
                <button
                  type="button"
                  className="pr-btn-reject"
                  onClick={() => { closeDetail(); openReject(r.transfer_id); }}
                >
                  REJECT
                </button>
                <button
                  type="button"
                  className="pr-btn-approve"
                  onClick={() => handleApprove(r.transfer_id)}
                  disabled={isApproving}
                >
                  {isApproving ? 'APPROVING…' : 'APPROVE'}
                </button>
              </div>
            )}
          </div>
        </div>
      </Modal>
    );
  };

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER: Reject modal
  // ─────────────────────────────────────────────────────────────────────────
  const renderRejectModal = () => (
    <Modal isOpen={rejectModal.open} onClose={() => setRejectModal(p => ({ ...p, open: false }))} title="Reject Transfer Request" maxWidth={480}>
      <div style={{ padding: '8px 0' }}>
        <label style={{ display: 'block', fontWeight: 600, fontSize: 13, marginBottom: 8, color: '#374151' }}>
          REJECTION REASON <span style={{ color: '#dc2626' }}>*</span>
        </label>
        <textarea
          style={{ width: '100%', minHeight: 90, border: '1px solid #e2e8f0', borderRadius: 8, padding: '10px 12px', fontSize: 13, fontFamily: 'inherit', resize: 'vertical', boxSizing: 'border-box' }}
          value={rejectModal.reason}
          onChange={e => setRejectModal(p => ({ ...p, reason: e.target.value }))}
          placeholder="Explain why this request is being rejected…"
        />
        {!rejectModal.reason.trim() && (
          <p style={{ color: '#dc2626', fontSize: 12, marginTop: 6 }}>Rejection reason is required.</p>
        )}
        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 16 }}>
          <button
            type="button"
            style={{ padding: '8px 18px', borderRadius: 8, border: '1px solid #e2e8f0', background: '#fff', cursor: 'pointer', fontSize: 13 }}
            onClick={() => setRejectModal(p => ({ ...p, open: false }))}
            disabled={rejectModal.submitting}
          >
            CANCEL
          </button>
          <button
            type="button"
            className="pr-btn-reject"
            onClick={handleReject}
            disabled={rejectModal.submitting || !rejectModal.reason.trim()}
          >
            {rejectModal.submitting ? 'REJECTING…' : 'CONFIRM REJECT'}
          </button>
        </div>
      </div>
    </Modal>
  );

  // ─────────────────────────────────────────────────────────────────────────
  // MAIN RENDER
  // ─────────────────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="po-recommendation-page">
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
                  strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
                  <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">TRANSFER REQUESTS</h1>
                <p className="po-page-subtitle">Request stock transfers between locations</p>
              </div>
            </div>
          </div>
        </div>

        <div className="po-tab-nav">
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'create' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('create')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <path d="M12 5v14M5 12h14"/>
            </svg>
            Create Request
          </button>
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'my-requests' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('my-requests')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <path d="M17 3l4 4-4 4"/><path d="M3 7h18"/>
              <path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/>
            </svg>
            My Requests
          </button>
          {isAdmin && (
            <button
              type="button"
              className={`po-tab-btn${activeTab === 'approvals' ? ' po-tab-btn--active' : ''}`}
              onClick={() => setActiveTab('approvals')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                <polyline points="9 11 12 14 22 4"/>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
              </svg>
              Pending Approvals
              {pendingReqs.length > 0 && activeTab !== 'approvals' && (
                <span className="pr-pending-badge">{pendingReqs.length}</span>
              )}
            </button>
          )}
        </div>

        {activeTab === 'create'      && renderCreate()}
        {activeTab === 'my-requests' && renderMyRequests()}
        {activeTab === 'approvals'   && isAdmin && renderApprovals()}
      </div>

      {renderDetailModal()}
      {renderRejectModal()}
    </AdminLayout>
  );
};

export default TransferRequestPage;
