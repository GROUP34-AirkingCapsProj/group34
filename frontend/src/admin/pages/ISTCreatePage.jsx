import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { istAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import DateInput from '../components/DateInput';

// ─── Helpers ──────────────────────────────────────────────────────────────────

const today = () => new Date().toISOString().slice(0, 10);
const userName = (u) => u ? ((`${u.first_name || ''} ${u.last_name || ''}`).trim() || u.username || '—') : '—';

const LockedField = ({ label, value }) => (
  <div className="po-field">
    <label className="po-field-label">{label}</label>
    <div style={{
      background: '#f8fafc',
      border: '1px solid #e2e8f0',
      borderRadius: 8,
      padding: '9px 12px',
      fontSize: 13,
      color: '#374151',
      minHeight: 38,
    }}>
      {value || '—'}
    </div>
  </div>
);

// ─── Main ─────────────────────────────────────────────────────────────────────

const ISTCreatePage = () => {
  const { requestId } = useParams();
  const navigate      = useNavigate();
  const { user }      = useAuth();

  const [loading,  setLoading]  = useState(true);
  const [loadErr,  setLoadErr]  = useState('');
  const [req,      setReq]      = useState(null);   // approved transfer request data

  // Per-line state: { [transfer_detail_id]: qty }
  const [qtys,     setQtys]     = useState({});
  const [txDate,   setTxDate]   = useState(today());
  const [submitting, setSubmitting] = useState(false);

  // Load pre-fill data
  useEffect(() => {
    if (!requestId) { setLoadErr('No request ID provided.'); setLoading(false); return; }
    istAPI.prepare(requestId)
      .then(res => {
        const data = res?.data;
        if (!data) throw new Error('Empty response');
        setReq(data);
        // Initialise quantities to approved (quantity_requested)
        const init = {};
        (data.details ?? []).forEach(d => { init[d.transfer_detail_id] = d.quantity_requested; });
        setQtys(init);
      })
      .catch(err => setLoadErr(err?.message || 'Failed to load request data.'))
      .finally(() => setLoading(false));
  }, [requestId]);

  const updateQty = useCallback((detailId, value) => {
    setQtys(p => ({ ...p, [detailId]: value }));
  }, []);

  const handleSubmit = useCallback(async () => {
    if (!txDate) { toast.error("Couldn't create IST", 'Transfer date is required.'); return; }
    if (!req)    { toast.error("Couldn't create IST", 'Request data not loaded.'); return; }

    const products = (req.details ?? []).map(d => ({
      transfer_detail_id:   d.transfer_detail_id,
      quantity_to_transfer: Math.max(0, parseInt(qtys[d.transfer_detail_id] ?? 0) || 0),
    }));

    const hasAny = products.some(p => p.quantity_to_transfer > 0);
    if (!hasAny) { toast.error("Couldn't create IST", 'At least one product must have a quantity greater than 0.'); return; }

    // Client-side guard: quantity cannot exceed approved
    for (const d of (req.details ?? [])) {
      const entered  = parseInt(qtys[d.transfer_detail_id] ?? 0) || 0;
      const approved = d.quantity_requested;
      if (entered > approved) {
        toast.error("Couldn't create IST", `Quantity for ${d.product?.product_name || `product #${d.product_id}`} cannot exceed the approved amount of ${approved}.`);
        return;
      }
    }

    if (!(await confirmAction({ title: 'Create this interbranch transfer?', confirmText: 'Create IST', tone: 'primary' }))) return;

    setSubmitting(true);
    try {
      const res = await istAPI.create({
        transfer_request_id: req.transfer_id,
        transfer_date:       txDate,
        products,
      });
      const istId = res?.data?.transfer_id;
      navigate(istId ? `/admin/ist/${istId}` : '/admin/ist', {
        state: { success: `IST ${res?.data?.transfer_number || ''} created successfully.` },
      });
    } catch (err) {
      toast.error("Couldn't create IST", err?.errors ? Object.values(err.errors).flat().join(' ') : err?.message || 'Failed to create IST.');
    } finally {
      setSubmitting(false);
    }
  }, [req, txDate, qtys, navigate]);

  // ── Loading / error states ────────────────────────────────────────────────
  if (loading) {
    return (
      <AdminLayout>
        <div className="ak-page po-recommendation-page">
          <div style={{ padding: 40, textAlign: 'center', color: '#64748b' }}>Loading request data…</div>
        </div>
      </AdminLayout>
    );
  }

  if (loadErr || !req) {
    return (
      <AdminLayout>
        <div className="ak-page po-recommendation-page">
          <div style={{ padding: 40, textAlign: 'center' }}>
            <p style={{ color: '#dc2626', fontWeight: 600 }}>{loadErr || 'Request not found or not yet approved.'}</p>
            <button type="button" className="po-btn" style={{ marginTop: 16 }} onClick={() => navigate('/admin/transfer-requests')}>
              ← Back to Transfer Requests
            </button>
          </div>
        </div>
      </AdminLayout>
    );
  }

  const from       = req.from_location?.location_name || req.fromLocation?.location_name || '—';
  const to         = req.to_location?.location_name   || req.toLocation?.location_name   || '—';
  const approver   = userName(req.approved_by || req.approvedBy);
  const requester  = userName(req.requested_by || req.requestedBy);
  const details    = Array.isArray(req.details) ? req.details : [];

  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page">

        {/* Page header */}
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <button
                type="button"
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#64748b', fontSize: 13, display: 'flex', alignItems: 'center', gap: 4, padding: 0, marginBottom: 8 }}
                onClick={() => navigate('/admin/transfer-requests')}
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                  <path d="M15 18l-6-6 6-6"/>
                </svg>
                Back to Transfer Requests
              </button>
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
                  strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <rect x="2" y="3" width="20" height="14" rx="2"/>
                  <path d="M8 21h8M12 17v4"/>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">CREATE INTERBRANCH STOCK TRANSFER</h1>
                <p className="po-page-subtitle">
                  From approved request <strong>{req.transfer_number}</strong>
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Locked header fields */}
        <div className="po-details-card">
          <div style={{ background: '#fffbeb', border: '1px solid #fbbf24', borderRadius: 8, padding: '10px 14px', marginBottom: 16, fontSize: 13, color: '#92400e', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="16" height="16" style={{ flexShrink: 0, marginTop: 1 }}>
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            You may <strong>reduce</strong> quantities but cannot exceed the approved amount. Set a product to 0 to exclude it from this IST.
          </div>

          <div className="pr-form-grid">
            <LockedField label="FROM LOCATION"  value={from} />
            <LockedField label="TO LOCATION"    value={to} />
            <LockedField label="REQUESTED BY"   value={requester} />
            <LockedField label="APPROVED BY"    value={approver} />
          </div>

          <div className="po-field" style={{ marginTop: 16, maxWidth: 260 }}>
            <label className="po-field-label">TRANSFER DATE *</label>
            <DateInput
              className="po-input"
              value={txDate}
              onChange={e => setTxDate(e.target.value)}
            />
          </div>
        </div>

        {/* Products table */}
        <div className="po-line-items-card">
          <div className="po-line-items-header">
            <div className="po-line-items-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
                <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>
              </svg>
              PRODUCTS TO TRANSFER
              <span className="po-line-items-count">{details.length}</span>
            </div>
          </div>

          <div className="po-table-wrapper">
            <table className="po-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>PRODUCT</th>
                  <th>APPROVED QTY</th>
                  <th>STOCK AT SOURCE</th>
                  <th>QTY TO TRANSFER</th>
                  <th>STATUS</th>
                </tr>
              </thead>
              <tbody>
                {details.map((d, idx) => {
                  const approved    = d.quantity_requested;
                  const stock       = d.stock_at_source ?? null;
                  const enteredRaw  = qtys[d.transfer_detail_id] ?? approved;
                  const entered     = parseInt(enteredRaw) || 0;
                  const overApproved  = entered > approved;
                  const exceedsStock  = stock !== null && entered > stock;
                  const excluded      = entered === 0;

                  return (
                    <tr key={d.transfer_detail_id}
                      style={excluded ? { opacity: 0.5 } : overApproved ? { background: '#fef2f2' } : {}}>
                      <td style={{ color: '#94a3b8', fontSize: 12 }}>{idx + 1}</td>
                      <td>
                        <div style={{ fontWeight: 500 }}>{d.product?.product_name || '—'}</div>
                        {d.product?.product_code && (
                          <small style={{ color: '#94a3b8' }}>{d.product.product_code}</small>
                        )}
                      </td>
                      <td>
                        <span style={{ fontWeight: 600, color: '#1e293b' }}>{approved}</span>
                      </td>
                      <td>
                        {stock !== null ? (
                          <span style={{ color: stock >= entered && !excluded ? '#16a34a' : '#dc2626', fontWeight: 600 }}>
                            {stock}
                            {exceedsStock && !excluded && (
                              <span title="Quantity exceeds available stock" style={{ marginLeft: 4 }}>⚠</span>
                            )}
                          </span>
                        ) : <span style={{ color: '#94a3b8' }}>—</span>}
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          max={approved}
                          className="po-qty-input"
                          value={enteredRaw}
                          onChange={e => updateQty(d.transfer_detail_id, e.target.value)}
                          style={{
                            width: 80,
                            borderColor: overApproved ? '#dc2626' : undefined,
                            outline: overApproved ? '2px solid #fca5a5' : undefined,
                          }}
                        />
                        {overApproved && (
                          <div style={{ color: '#dc2626', fontSize: 11, marginTop: 2 }}>
                            Max {approved}
                          </div>
                        )}
                      </td>
                      <td>
                        {excluded
                          ? <span style={{ fontSize: 11, color: '#94a3b8', background: '#f1f5f9', borderRadius: 4, padding: '2px 6px' }}>EXCLUDED</span>
                          : overApproved
                            ? <span style={{ fontSize: 11, color: '#dc2626', background: '#fef2f2', borderRadius: 4, padding: '2px 6px' }}>OVER LIMIT</span>
                            : exceedsStock
                              ? <span style={{ fontSize: 11, color: '#d97706', background: '#fffbeb', borderRadius: 4, padding: '2px 6px' }}>LOW STOCK</span>
                              : <span style={{ fontSize: 11, color: '#16a34a', background: '#f0fdf4', borderRadius: 4, padding: '2px 6px' }}>OK</span>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>


        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 12, marginTop: 12 }}>
          <button
            type="button"
            style={{ padding: '10px 20px', borderRadius: 8, border: '1px solid #e2e8f0', background: '#fff', cursor: 'pointer', fontSize: 13, color: '#374151' }}
            onClick={() => navigate('/admin/transfer-requests')}
            disabled={submitting}
          >
            Cancel
          </button>
          <button
            type="button"
            className="po-btn po-btn-final-submission"
            onClick={handleSubmit}
            disabled={submitting}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
              <path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>
            </svg>
            {submitting ? 'CREATING IST…' : 'SAVE AND PROCEED TO SCAN'}
          </button>
        </div>

      </div>
    </AdminLayout>
  );
};

export default ISTCreatePage;
