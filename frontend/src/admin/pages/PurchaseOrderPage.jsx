import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import {
  suppliersAPI,
  purchaseOrdersAPI,
  purchaseRequestsAPI,
  getToken,
  getApiBaseUrl,
} from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import { exportPOToExcel, exportPOToDocument } from '../utils/poExport';
import PRProgressBar from '../components/PRProgressBar';

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtPeso = (n) =>
  `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const getStatusConfig = (statusName) => {
  const s = String(statusName || '').toLowerCase();
  if (s.includes('complet'))                            return { label: 'COMPLETED',          color: 'completed' };
  if (s.includes('partial'))                            return { label: 'PARTIALLY RECEIVED', color: 'partial'   };
  if (s.includes('approv') || s.includes('authoriz'))   return { label: 'APPROVED',           color: 'approved'  };
  if (s.includes('reject'))                             return { label: 'REJECTED',            color: 'rejected'  };
  if (s.includes('cancel'))                             return { label: 'CANCELLED',           color: 'cancelled' };
  if (s.includes('pending'))                            return { label: 'PENDING APPROVAL',    color: 'pending'   };
  return { label: String(statusName || 'DRAFT').toUpperCase(), color: 'draft' };
};

const getPriorityFromAmount = (total, itemCount) => {
  const n = Number(total || 0);
  if (n >= 1_000_000 || itemCount >= 4) return { label: 'HIGH',   color: 'high'   };
  if (n >= 500_000   || itemCount >= 2) return { label: 'MEDIUM', color: 'medium' };
  return { label: 'LOW', color: 'low' };
};

// V5 product detail label for PO line items (snake_case backend fields)
const poLineLabel = (item) => {
  if (item.product_type === 'consumable') {
    const u   = item.unit_name;
    const ppp = item.pieces_per_package;
    const pkg = item.package_unit;
    if (u && ppp && pkg) return `${u} · ${ppp}pcs/${pkg}`;
    if (u && ppp)         return `${u} · ${ppp}pcs`;
    return u || '—';
  }
  return [item.brand_name, item.capacity_rating, item.variant].filter(Boolean).join(' ') || '—';
};


// ── Component ─────────────────────────────────────────────────────────────────

const PurchaseOrderPage = () => {
  const navigate = useNavigate();

  // Tabs
  const [activeTab, setActiveTab] = useState('create');

  // Suppliers
  const [suppliers,          setSuppliers]          = useState([]);
  const [loadingSuppliers,   setLoadingSuppliers]   = useState(true);
  const [selectedSupplierId, setSelectedSupplierId] = useState('');

  // Expected delivery
  const [expectedDeliveryDate, setExpectedDeliveryDate] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
  });

  // Approved PRs list
  const [approvedPRs, setApprovedPRs] = useState([]);
  const [loadingPRs,  setLoadingPRs]  = useState(false);
  const [prLoadError, setPrLoadError] = useState('');

  // Selected PR and its editable line items
  const [selectedPR,    setSelectedPR]    = useState(null);
  const [lineItems,     setLineItems]     = useState([]);
  const [reorderNotes,  setReorderNotes]  = useState({}); // { product_id: openCount }

  // Submit
  const [submitting,     setSubmitting]     = useState(false);
  const [submitError,    setSubmitError]    = useState('');
  const [convertSuccess, setConvertSuccess] = useState(null); // { po_number, pr_number }

  // PO Status tab
  const [poStatusList,    setPoStatusList]    = useState([]);
  const [loadingPOStatus, setLoadingPOStatus] = useState(false);
  const [poStatusError,   setPoStatusError]   = useState('');
  const [selectedPO,      setSelectedPO]      = useState(null);

  // ── Load suppliers once ────────────────────────────────────────────────────
  useEffect(() => {
    let mounted = true;
    suppliersAPI.getAll({ per_page: 200 })
      .then(res => {
        if (!mounted) return;
        const list = Array.isArray(res?.data) ? res.data : [];
        setSuppliers(list);
        if (list.length) setSelectedSupplierId(String(list[0].supplier_id ?? list[0].id ?? ''));
      })
      .catch(() => {})
      .finally(() => { if (mounted) setLoadingSuppliers(false); });
    return () => { mounted = false; };
  }, []);

  // ── Load approved PRs when on create tab ──────────────────────────────────
  useEffect(() => {
    if (activeTab !== 'create') return;
    let mounted = true;
    setLoadingPRs(true);
    setPrLoadError('');
    purchaseRequestsAPI.getAll({ status_id: 14, per_page: 100 })
      .then(res => {
        if (!mounted) return;
        const prs = Array.isArray(res?.data) ? res.data : [];
        setApprovedPRs(prs.filter(pr => !pr.converted_to_po_id));
      })
      .catch(err => {
        if (!mounted) return;
        setPrLoadError(err?.message || 'Failed to load approved purchase requests.');
      })
      .finally(() => { if (mounted) setLoadingPRs(false); });
    return () => { mounted = false; };
  }, [activeTab]);

  // ── Load PO status list ────────────────────────────────────────────────────
  useEffect(() => {
    if (activeTab !== 'status') return;
    let mounted = true;
    setLoadingPOStatus(true);
    setPoStatusError('');
    purchaseOrdersAPI.getAll({ per_page: 100 })
      .then(res => {
        if (!mounted) return;
        setPoStatusList(Array.isArray(res?.data) ? res.data : []);
      })
      .catch(err => {
        if (!mounted) return;
        setPoStatusError(err?.message || 'Failed to load purchase orders.');
      })
      .finally(() => { if (mounted) setLoadingPOStatus(false); });
    return () => { mounted = false; };
  }, [activeTab]);

  // ── PR selection ───────────────────────────────────────────────────────────
  const handleSelectPR = useCallback((pr) => {
    setSelectedPR(pr);
    setSubmitError('');
    setConvertSuccess(null);
    setReorderNotes({});
    const details = Array.isArray(pr.details) ? pr.details : [];
    setLineItems(details.map(d => {
      const prQty     = Number(d.quantity || 0);
      const unitPrice = Number(d.estimated_unit_price ?? d.unit_price ?? d.estimated_price ?? 0);
      return {
        id:                `pr-line-${d.pr_detail_id ?? d.id ?? Math.random()}`,
        product_id:        d.product_id,
        product:           d.product_name || d.product?.product_name || `Product ${d.product_id}`,
        product_code:      d.product_code || d.product?.product_code || '',
        product_type:      d.product_type || d.product?.product_type || '',
        brand_name:        d.brand_name ?? null,
        capacity_rating:   d.capacity_rating ?? null,
        variant:           d.variant ?? null,
        unit_name:         d.unit_name ?? null,
        pieces_per_package: d.pieces_per_package ?? null,
        package_unit:      d.package_unit ?? null,
        pr_qty:            prQty,
        quantity_ordered:  prQty,
        unit_price:        unitPrice,
        subtotal:          prQty * unitPrice,
      };
    }));
    // Fetch open demand for each product (excluding this PR) to show informational notes
    details.forEach(d => {
      if (!d.product_id) return;
      purchaseRequestsAPI.getOpenLinesForProduct(d.product_id)
        .then(res => {
          const lines = Array.isArray(res?.data) ? res.data : [];
          const otherOpen = lines.filter(l => l.pr_id !== pr.pr_id);
          if (otherOpen.length > 0) {
            const total = otherOpen.reduce((s, l) => s + (l.quantity_remaining || 0), 0);
            setReorderNotes(prev => ({ ...prev, [d.product_id]: total }));
          }
        })
        .catch(() => {});
    });
  }, []);

  const handleDeselectPR = useCallback(() => {
    setSelectedPR(null);
    setLineItems([]);
    setSubmitError('');
    setReorderNotes({});
  }, []);

  // ── Line item editing ──────────────────────────────────────────────────────
  const updateOrderQty = useCallback((id, val) => {
    const newQty = Math.max(1, parseInt(val) || 1);
    setLineItems(prev => prev.map(li =>
      li.id === id ? { ...li, quantity_ordered: newQty, subtotal: newQty * li.unit_price } : li
    ));
  }, []);

  const updateUnitPrice = useCallback((id, val) => {
    const newPrice = Math.max(0, parseFloat(val) || 0);
    setLineItems(prev => prev.map(li =>
      li.id === id ? { ...li, unit_price: newPrice, subtotal: li.quantity_ordered * newPrice } : li
    ));
  }, []);

  // ── Computed totals ────────────────────────────────────────────────────────
  const prEstimatedTotal = useMemo(
    () => lineItems.reduce((s, li) => s + li.pr_qty * li.unit_price, 0),
    [lineItems]
  );
  const poTotal = useMemo(
    () => lineItems.reduce((s, li) => s + li.subtotal, 0),
    [lineItems]
  );
  const hasPartialLines = useMemo(
    () => lineItems.some(li => li.quantity_ordered < li.pr_qty),
    [lineItems]
  );

  const selectedSupplierName = useMemo(() => {
    const found = suppliers.find(s => String(s.supplier_id ?? s.id) === String(selectedSupplierId));
    return found?.supplier_name ?? found?.name ?? '—';
  }, [suppliers, selectedSupplierId]);

  // ── Export PO list ─────────────────────────────────────────────────────────
  const handleExport = useCallback(async () => {
    try {
      const token = getToken();
      const base  = getApiBaseUrl();
      const res   = await fetch(`${base}/purchase-orders/export`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
      });
      if (!res.ok) throw new Error(`Export failed (${res.status})`);
      const blob = await res.blob();
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href     = url;
      a.download = `purchase-orders-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      alert('Export failed: ' + err.message);
    }
  }, []);

  // ── Submit ─────────────────────────────────────────────────────────────────
  const handleSubmitPO = useCallback(async () => {
    setSubmitError('');
    if (!selectedPR) {
      setSubmitError('Please select an approved Purchase Request first.');
      return;
    }
    if (!selectedSupplierId) {
      setSubmitError('Please select a supplier.');
      return;
    }
    if (lineItems.length === 0) {
      setSubmitError('No line items to submit.');
      return;
    }
    setSubmitting(true);
    try {
      const payload = {
        supplier_id:            Number(selectedSupplierId),
        expected_delivery_date: expectedDeliveryDate || null,
        details: lineItems.map(li => ({
          product_id:       li.product_id,
          quantity_ordered: li.quantity_ordered,
          unit_price:       li.unit_price,
          subtotal:         li.quantity_ordered * li.unit_price,
        })),
      };
      const res   = await purchaseRequestsAPI.convertToPO(selectedPR.pr_id, payload);
      const data  = res?.data;
      const poNum = data?.po_number || data?.po?.po_number || 'New PO';
      setConvertSuccess({ po_number: poNum, pr_number: selectedPR.pr_number });
      setApprovedPRs(prev => prev.filter(p => p.pr_id !== selectedPR.pr_id));
      setSelectedPR(null);
      setLineItems([]);
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to create purchase order.';
      setSubmitError(msg);
    } finally {
      setSubmitting(false);
    }
  }, [selectedPR, selectedSupplierId, expectedDeliveryDate, lineItems]);

  // ── PO Status renderer ─────────────────────────────────────────────────────
  const renderPoStatusView = () => {
    if (loadingPOStatus) return <div className="po-status-loading">Loading purchase orders…</div>;
    if (poStatusError)   return <div className="po-status-error-msg">{poStatusError}</div>;
    if (poStatusList.length === 0) return <div className="po-status-empty">No purchase orders found.</div>;

    return (
      <div className="po-status-list">
        {poStatusList.map((po) => {
          const statusCfg = getStatusConfig(po.status?.status_name);
          const amount    = Number(po.grand_total || po.total_amount || 0);
          const itemCount = po.details?.length ?? 0;
          const priCfg    = getPriorityFromAmount(amount, itemCount);
          const createdByName = po.created_by
            ? `${po.created_by?.first_name || ''} ${po.created_by?.last_name || ''}`.trim() || po.created_by?.username || '—'
            : '—';
          const actorName = po.approved_by
            ? `${po.approved_by?.first_name || ''} ${po.approved_by?.last_name || ''}`.trim() || po.approved_by?.username || 'Admin User'
            : createdByName;
          const actionDate = po.updated_at ? String(po.updated_at).slice(0, 10) : '—';
          const receivedByName = po.received_by && typeof po.received_by === 'object'
            ? `${po.received_by?.first_name || ''} ${po.received_by?.last_name || ''}`.trim() || po.received_by?.username || '—'
            : '—';

          return (
            <div key={po.po_id} className="po-status-card">
              {po.purchase_request && (
                <div style={{ background: '#eff6ff', borderBottom: '1px solid #bfdbfe', padding: '6px 16px', fontSize: 12, color: '#1d4ed8', borderRadius: '10px 10px 0 0' }}>
                  📋 From PR:{' '}
                  <button
                    type="button"
                    style={{ background: 'none', border: 'none', color: '#1d4ed8', cursor: 'pointer', fontWeight: 600, textDecoration: 'underline', fontSize: 12, padding: 0 }}
                    onClick={() => navigate('/admin/purchase-requests')}
                  >
                    {po.purchase_request.pr_number}
                  </button>
                </div>
              )}
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{po.po_number}</span>
                    <span className={`po-status-badge po-status-badge--${statusCfg.color}`}>
                      {statusCfg.color === 'completed' && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>}
                      {statusCfg.color === 'partial'   && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>}
                      {statusCfg.color === 'pending'   && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>}
                      {statusCfg.color === 'approved'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>}
                      {statusCfg.color === 'rejected'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>}
                      {statusCfg.color === 'cancelled' && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14H6L5 6"></path><path d="M9 6V4h6v2"></path></svg>}
                      {statusCfg.label}
                    </span>
                    <span className={`po-priority-badge po-priority-badge--${priCfg.color}`}>{priCfg.label}</span>
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">SUPPLIER</span>
                      <span className="po-status-meta-value">{po.supplier?.supplier_name || '—'}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">CREATED BY</span>
                      <span className="po-status-meta-value">{createdByName}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">CREATED DATE</span>
                      <span className="po-status-meta-value">{po.order_date ? String(po.order_date).slice(0, 10) : '—'}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">ITEMS</span>
                      <span className="po-status-meta-value">{itemCount} {itemCount === 1 ? 'item' : 'items'}</span>
                    </div>
                  </div>
                </div>
                <div className="po-status-card-right">
                  <span className="po-status-total-label">TOTAL AMOUNT</span>
                  <span className="po-status-total-amount">₱{amount.toLocaleString('en-PH')}</span>
                  <button type="button" className="po-status-view-btn" onClick={() => setSelectedPO(po)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    VIEW DETAILS
                  </button>
                </div>
              </div>
              {statusCfg.color === 'approved'  && <div className="po-status-note po-status-note--approved"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg><span><strong>Approved by {actorName}</strong> on {actionDate}</span></div>}
              {statusCfg.color === 'rejected'  && <div className="po-status-note po-status-note--rejected"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg><span><strong>Rejected by {actorName}</strong> on {actionDate}</span></div>}
              {statusCfg.color === 'cancelled' && <div className="po-status-note po-status-note--cancelled"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14H6L5 6"></path><path d="M9 6V4h6v2"></path></svg><span><strong>Cancelled by {actorName}</strong> on {actionDate}</span></div>}
              {statusCfg.color === 'completed' && <div className="po-status-note po-status-note--completed"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg><span><strong>Fully received{receivedByName !== '—' ? ` by ${receivedByName}` : ''}</strong> on {actionDate}</span></div>}
              {statusCfg.color === 'partial'   && <div className="po-status-note po-status-note--partial"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg><span><strong>Partially received{receivedByName !== '—' ? ` by ${receivedByName}` : ''}</strong> on {actionDate} — receiving in progress</span></div>}
            </div>
          );
        })}
      </div>
    );
  };

  // ── Convert PR Tab renderer ────────────────────────────────────────────────
  const renderConvertPRTab = () => {
    // Step 1 — select a PR
    if (!selectedPR) {
      return (
        <div className="po-details-card">
          <div style={{ marginBottom: 16 }}>
            <h3 style={{ fontSize: 14, fontWeight: 700, color: '#1e293b', textTransform: 'uppercase', letterSpacing: '0.06em', margin: '0 0 4px' }}>
              Step 1: Select an Approved Purchase Request
            </h3>
            <p style={{ fontSize: 13, color: '#64748b', margin: 0 }}>
              Choose an approved PR to convert into a Purchase Order.
            </p>
          </div>

          {loadingPRs && <div className="po-status-loading">Loading approved purchase requests…</div>}
          {prLoadError && <div className="po-status-error-msg">{prLoadError}</div>}

          {!loadingPRs && !prLoadError && approvedPRs.length === 0 && (
            <div style={{ textAlign: 'center', padding: '32px 16px' }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="48" height="48" style={{ display: 'block', margin: '0 auto 12px' }}>
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
              </svg>
              <p style={{ fontWeight: 600, color: '#475569', marginBottom: 4 }}>No approved Purchase Requests available.</p>
              <p style={{ fontSize: 13, color: '#94a3b8', marginBottom: 16 }}>
                Create a Purchase Request first and get it approved by Admin.
              </p>
              <button type="button" className="po-btn po-btn-add-product" onClick={() => navigate('/admin/purchase-requests')}>
                Go to Purchase Requests →
              </button>
            </div>
          )}

          {!loadingPRs && !prLoadError && approvedPRs.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {approvedPRs.map(pr => {
                const itemCount = Array.isArray(pr.details) ? pr.details.length : 0;
                const neededBy  = pr.needed_by_date ? String(pr.needed_by_date).slice(0, 10) : null;
                const estTotal  = Array.isArray(pr.details)
                  ? pr.details.reduce((s, d) => s + Number(d.quantity || 0) * Number(d.estimated_unit_price ?? d.unit_price ?? 0), 0)
                  : 0;
                const reqBy = (pr.requester || pr.requested_by)
                  ? `${(pr.requester || pr.requested_by).first_name || ''} ${(pr.requester || pr.requested_by).last_name || ''}`.trim() || (pr.requester || pr.requested_by).username || '—'
                  : '—';
                return (
                  <div key={pr.pr_id} className="po-status-card" style={{ padding: '14px 18px' }}>
                    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }}>
                      <div style={{ flex: 1 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                          <span style={{ fontWeight: 700, fontSize: 15, color: '#1e293b' }}>{pr.pr_number}</span>
                          <span className="pr-badge pr-badge--approved">APPROVED</span>
                        </div>
                        <div style={{ fontSize: 13, color: '#475569', display: 'flex', flexWrap: 'wrap', gap: '4px 16px' }}>
                          {pr.location?.location_name && <span>📍 {pr.location.location_name}</span>}
                          <span>{itemCount} {itemCount === 1 ? 'item' : 'items'}</span>
                          {neededBy && <span>Needed by: {neededBy}</span>}
                          <span>Requested by: {reqBy}</span>
                          {estTotal > 0 && <span>Est: {fmtPeso(estTotal)}</span>}
                        </div>
                      </div>
                      <button
                        type="button"
                        className="po-btn po-btn-final-submission"
                        style={{ whiteSpace: 'nowrap', fontSize: 13, padding: '8px 16px' }}
                        onClick={() => handleSelectPR(pr)}
                      >
                        SELECT →
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      );
    }

    // Step 2 — PR selected, fill PO form
    const locationName = selectedPR.location?.location_name || '—';

    return (
      <div>
        {/* PR info banner */}
        <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, padding: '10px 16px', marginBottom: 16, fontSize: 13, color: '#1e40af', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <span>📋 Creating PO from Purchase Request: <strong>{selectedPR.pr_number}</strong></span>
          <button type="button" style={{ background: 'none', border: 'none', color: '#1d4ed8', cursor: 'pointer', fontSize: 13, textDecoration: 'underline', padding: 0 }} onClick={handleDeselectPR}>
            ← Change PR
          </button>
        </div>

        {/* Progress bar */}
        <div className="po-details-card" style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 11, fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 6 }}>
            PR → PO Progress
          </div>
          <PRProgressBar pr={selectedPR} converting={true} />
          <p style={{ fontSize: 11, color: '#94a3b8', margin: '4px 0 0' }}>
            PO will be submitted for Admin approval after creation.
          </p>
        </div>

        <div className="po-content-layout">
          <div className="po-main-content">
            {/* Form fields */}
            <div className="po-details-card">
              <div className="po-details-grid">
                <div className="po-field">
                  <label className="po-field-label">DELIVERY LOCATION</label>
                  <div className="po-reference-badge">{locationName}</div>
                </div>
                <div className="po-field">
                  <label className="po-field-label">SUPPLIER</label>
                  <select className="po-select" value={selectedSupplierId} onChange={e => setSelectedSupplierId(e.target.value)} disabled={loadingSuppliers}>
                    {loadingSuppliers && <option value="">Loading suppliers…</option>}
                    {!loadingSuppliers && suppliers.length === 0 && <option value="">No suppliers found</option>}
                    {suppliers.map(s => (
                      <option key={s.supplier_id ?? s.id} value={String(s.supplier_id ?? s.id)}>
                        {s.supplier_name ?? s.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="po-field">
                  <label className="po-field-label">EXPECTED DELIVERY DATE</label>
                  <input type="date" className="po-input" value={expectedDeliveryDate} onChange={e => setExpectedDeliveryDate(e.target.value)} />
                </div>
              </div>
            </div>

            {/* Line items */}
            <div className="po-line-items-card">
              <div className="po-line-items-header">
                <div className="po-line-items-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                  LINE ITEMS
                  <span className="po-line-items-count">{lineItems.length}</span>
                </div>
              </div>

              {lineItems.length === 0 ? (
                <div className="po-line-items-empty"><p>No items found in this Purchase Request.</p></div>
              ) : (
                <div className="po-table-wrapper">
                  <table className="po-table">
                    <thead>
                      <tr>
                        <th>PRODUCT</th>
                        <th>TYPE</th>
                        <th>DETAILS</th>
                        <th>PR QTY</th>
                        <th>ORDER QTY</th>
                        <th>UNIT PRICE (₱)</th>
                        <th>SUBTOTAL</th>
                        <th>STATUS</th>
                      </tr>
                    </thead>
                    <tbody>
                      {lineItems.map(li => {
                        const isPartial   = li.quantity_ordered < li.pr_qty;
                        const remaining   = li.pr_qty - li.quantity_ordered;
                        const openDemand  = reorderNotes[li.product_id] || 0;
                        return (
                          <React.Fragment key={li.id}>
                            <tr className={isPartial ? 'pr-item-remaining' : ''}>
                              <td>
                                <strong>{li.product}</strong>
                                {li.product_code && <div style={{ fontSize: 11, color: '#94a3b8' }}>{li.product_code}</div>}
                              </td>
                              <td>
                                <span className={`po-type-badge ${li.product_type === 'consumable' ? 'po-type-part' : 'po-type-appliance'}`}>
                                  {li.product_type === 'consumable' ? 'CONSUMABLE' : 'APPLIANCE'}
                                </span>
                              </td>
                              <td style={{ fontSize: 12, color: '#64748b' }}>{poLineLabel(li)}</td>
                              <td style={{ color: '#64748b', fontWeight: 500 }}>{li.pr_qty}</td>
                              <td>
                                <input
                                  className="po-qty-input"
                                  type="number"
                                  min="1"
                                  max={li.pr_qty}
                                  value={li.quantity_ordered}
                                  onChange={e => updateOrderQty(li.id, e.target.value)}
                                />
                              </td>
                              <td>
                                <input
                                  className="po-qty-input"
                                  type="number"
                                  min="0"
                                  step="0.01"
                                  value={li.unit_price}
                                  onChange={e => updateUnitPrice(li.id, e.target.value)}
                                  style={{ width: 90 }}
                                />
                              </td>
                              <td><strong>{fmtPeso(li.subtotal)}</strong></td>
                              <td style={{ fontSize: 12 }}>
                                {isPartial
                                  ? <span style={{ color: '#d97706' }}>⚠ Partial — {remaining} remaining</span>
                                  : <span style={{ color: '#16a34a' }}>✓ Full</span>
                                }
                              </td>
                            </tr>
                            {openDemand > 0 && (
                              <tr>
                                <td colSpan={8} style={{ padding: '0 8px 8px', background: '#fefce8' }}>
                                  <div style={{ fontSize: 11, color: '#713f12', padding: '4px 8px', display: 'flex', alignItems: 'center', gap: 5 }}>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="12" height="12" style={{ flexShrink: 0 }}>
                                      <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
                                    </svg>
                                    ℹ {openDemand} more unit(s) of <strong style={{ marginLeft: 3 }}>{li.product}</strong> on open demand from other PRs.
                                  </div>
                                </td>
                              </tr>
                            )}
                          </React.Fragment>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          {/* Summary sidebar */}
          <div className="po-summary-sidebar">
            <div className="po-summary-card">
              <h3 className="po-summary-title">ORDER SUMMARY</h3>
              <div className="po-summary-row">
                <span>PR ESTIMATED TOTAL</span>
                <span className="po-summary-value">{fmtPeso(prEstimatedTotal)}</span>
              </div>
              <div className="po-summary-row po-summary-row--logistics">
                <span>PO TOTAL</span>
                <span className={`po-summary-value${hasPartialLines ? ' po-summary-vat' : ''}`}>{fmtPeso(poTotal)}</span>
              </div>
              {hasPartialLines && (
                <div style={{ background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, padding: '8px 10px', fontSize: 12, color: '#92400e', marginTop: 8 }}>
                  ⚠ This PO is {fmtPeso(prEstimatedTotal - poTotal)} less than the PR. PR will be Partially Converted.
                </div>
              )}
              <div className="po-summary-total" style={{ marginTop: 12 }}>
                <span>FINAL PO TOTAL</span>
                <span className="po-summary-total-value">{fmtPeso(poTotal)}</span>
              </div>
            </div>

            <button
              type="button"
              className="po-btn po-btn-final-submission"
              style={{ width: '100%', marginTop: 12 }}
              onClick={handleSubmitPO}
              disabled={submitting}
            >
              {submitting ? 'SUBMITTING…' : 'SUBMIT PURCHASE ORDER'}
            </button>

            {submitError && (
              <div className="po-submit-error" style={{ marginTop: 8 }}>{submitError}</div>
            )}
          </div>
        </div>
      </div>
    );
  };

  // ── Main render ────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="po-recommendation-page po-draft-creator-page">
        {convertSuccess && (
          <div className="po-success-banner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <strong>{convertSuccess.po_number}</strong> created from <strong>{convertSuccess.pr_number}</strong>! Waiting for Admin approval.
            <button type="button" className="po-success-close" onClick={() => setConvertSuccess(null)}>✕</button>
          </div>
        )}

        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="16" y1="13" x2="8" y2="13"></line>
                  <line x1="16" y1="17" x2="8" y2="17"></line>
                  <line x1="10" y1="9" x2="8" y2="9"></line>
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">PURCHASE ORDERS</h1>
                <p className="po-page-subtitle">Convert approved Purchase Requests into Purchase Orders</p>
              </div>
            </div>
            <div style={{ display: 'flex', alignItems: 'center' }}>
              <button
                type="button"
                onClick={handleExport}
                style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, fontSize: 13, fontWeight: 600, color: '#374151', cursor: 'pointer' }}
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                EXPORT CSV
              </button>
            </div>
          </div>
        </div>

        <div className="po-tab-nav">
          <button type="button" className={`po-tab-btn${activeTab === 'create' ? ' po-tab-btn--active' : ''}`} onClick={() => setActiveTab('create')}>
            Convert PR to PO
          </button>
          <button type="button" className={`po-tab-btn${activeTab === 'status' ? ' po-tab-btn--active' : ''}`} onClick={() => setActiveTab('status')}>
            PO Status
          </button>
        </div>

        {activeTab === 'status' && renderPoStatusView()}
        {activeTab === 'create' && renderConvertPRTab()}
      </div>

      {/* PO Detail Modal */}
      <Modal isOpen={!!selectedPO} onClose={() => setSelectedPO(null)} title="" hideHeader maxWidth={700}>
        {selectedPO && (() => {
          const d         = selectedPO;
          const statusCfg = getStatusConfig(d.status?.status_name);
          const amount    = Number(d.grand_total || d.total_amount || 0);
          const priCfg    = getPriorityFromAmount(amount, d.details?.length ?? 0);
          const createdByName = d.created_by
            ? `${d.created_by.first_name || ''} ${d.created_by.last_name || ''}`.trim() || d.created_by.username || '—'
            : '—';
          const actorName = d.approved_by
            ? `${d.approved_by.first_name || ''} ${d.approved_by.last_name || ''}`.trim() || d.approved_by.username || '—'
            : '—';
          const details = Array.isArray(d.details) ? d.details : [];

          return (
            <div className="po-detail-modal">
              <div className="po-detail-head">
                <div className="po-detail-head-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="26" height="26">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <line x1="10" y1="9" x2="8" y2="9"></line>
                  </svg>
                </div>
                <div className="po-detail-head-info">
                  <h2 className="po-detail-head-number">{d.po_number}</h2>
                  {d.purchase_request && (
                    <p style={{ fontSize: 12, color: '#1d4ed8', margin: '2px 0 0' }}>
                      Source PR:{' '}
                      <button
                        type="button"
                        style={{ background: 'none', border: 'none', color: '#1d4ed8', cursor: 'pointer', textDecoration: 'underline', fontSize: 12, padding: 0 }}
                        onClick={() => { setSelectedPO(null); navigate('/admin/purchase-requests'); }}
                      >
                        {d.purchase_request.pr_number}
                      </button>
                    </p>
                  )}
                  <p className="po-detail-head-sub">
                    {d.supplier?.supplier_name || '—'} &nbsp;·&nbsp; {d.order_date ? String(d.order_date).slice(0, 10) : '—'}
                  </p>
                </div>
                <div className="po-detail-head-badges">
                  <span className={`po-detail-status-pill po-detail-status-pill--${statusCfg.color}`}>{statusCfg.label}</span>
                  <span className={`po-detail-priority-pill po-detail-priority-pill--${priCfg.color}`}>{priCfg.label}</span>
                </div>
                <button type="button" className="po-detail-head-close" onClick={() => setSelectedPO(null)} aria-label="Close">×</button>
              </div>

              {d.purchase_request && (
                <div style={{ padding: '10px 20px', background: '#f8fafc', borderBottom: '1px solid #e2e8f0' }}>
                  <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 4 }}>PR → PO Progress</div>
                  <PRProgressBar pr={{
                    status_id: 14,
                    converted_to_po_id: d.po_id,
                    convertedPO: { status: d.status },
                  }} showLabels={true} />
                </div>
              )}

              <div className={`po-detail-strip po-detail-strip--${statusCfg.color}`}>
                {statusCfg.color === 'pending'   && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>}
                {statusCfg.color === 'approved'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>}
                {statusCfg.color === 'rejected'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>}
                {statusCfg.color === 'cancelled' && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>}
                <span>
                  <strong>{statusCfg.label}</strong>
                  {(statusCfg.color === 'approved' || statusCfg.color === 'rejected') && actorName !== '—' && ` — by ${actorName}`}
                </span>
              </div>

              <div className="po-detail-body">
                <div className="po-detail-address-grid">
                  <div className="po-detail-address-block">
                    <div className="po-detail-address-block-title">Supplier / Vendor</div>
                    <div className="po-detail-address-row"><span className="po-detail-address-label">Name</span><span className="po-detail-address-value po-detail-address-value--name">{d.supplier?.supplier_name || '—'}</span></div>
                    {d.supplier?.address       && <div className="po-detail-address-row"><span className="po-detail-address-label">Address</span><span className="po-detail-address-value">{d.supplier.address}</span></div>}
                    {(d.supplier?.origin || d.supplier?.region) && <div className="po-detail-address-row"><span className="po-detail-address-label">Origin / Region</span><span className="po-detail-address-value">{[d.supplier.origin, d.supplier.region].filter(Boolean).join(', ')}</span></div>}
                    {d.supplier?.contact_person && <div className="po-detail-address-row"><span className="po-detail-address-label">Contact Person</span><span className="po-detail-address-value">{d.supplier.contact_person}</span></div>}
                    {d.supplier?.contact_number && <div className="po-detail-address-row"><span className="po-detail-address-label">Phone</span><span className="po-detail-address-value">{d.supplier.contact_number}</span></div>}
                    {d.supplier?.email          && <div className="po-detail-address-row"><span className="po-detail-address-label">Email</span><span className="po-detail-address-value">{d.supplier.email}</span></div>}
                    {d.supplier?.tin            && <div className="po-detail-address-row"><span className="po-detail-address-label">TIN</span><span className="po-detail-address-value">{d.supplier.tin}</span></div>}
                  </div>
                  <div className="po-detail-address-block">
                    <div className="po-detail-address-block-title">Ship To / Deliver To</div>
                    <div className="po-detail-address-row"><span className="po-detail-address-label">Branch / Location</span><span className="po-detail-address-value po-detail-address-value--name">{d.location?.location_name || '—'}</span></div>
                    {(d.location?.address || d.location?.city || d.location?.province || d.location?.region) && <div className="po-detail-address-row"><span className="po-detail-address-label">Address</span><span className="po-detail-address-value">{[d.location.address, d.location.city, d.location.province, d.location.region].filter(Boolean).join(', ')}</span></div>}
                    {d.location?.contact_number && <div className="po-detail-address-row"><span className="po-detail-address-label">Phone</span><span className="po-detail-address-value">{d.location.contact_number}</span></div>}
                    {d.location?.email          && <div className="po-detail-address-row"><span className="po-detail-address-label">Email</span><span className="po-detail-address-value">{d.location.email}</span></div>}
                    {d.location?.manager_name   && <div className="po-detail-address-row"><span className="po-detail-address-label">Manager</span><span className="po-detail-address-value">{d.location.manager_name}</span></div>}
                  </div>
                </div>

                <div className="po-detail-info-grid">
                  <div className="po-detail-info-item"><span className="po-detail-info-label">CREATED BY</span><span className="po-detail-info-value">{createdByName}</span></div>
                  <div className="po-detail-info-item"><span className="po-detail-info-label">ORDER DATE</span><span className="po-detail-info-value">{d.order_date ? String(d.order_date).slice(0, 10) : '—'}</span></div>
                  <div className="po-detail-info-item"><span className="po-detail-info-label">EXPECTED DELIVERY</span><span className="po-detail-info-value">{d.expected_delivery_date ? String(d.expected_delivery_date).slice(0, 10) : '—'}</span></div>
                  <div className="po-detail-info-item"><span className="po-detail-info-label">TOTAL AMOUNT</span><span className="po-detail-info-value" style={{ color: '#dc2626', fontWeight: 800 }}>₱{amount.toLocaleString('en-PH')}</span></div>
                  {actorName !== '—' && (
                    <div className="po-detail-info-item">
                      <span className="po-detail-info-label">{statusCfg.color === 'rejected' ? 'REVIEWED BY' : 'APPROVED BY'}</span>
                      <span className="po-detail-info-value">{actorName}</span>
                    </div>
                  )}
                </div>

                <div className="po-detail-items-wrap">
                  <div className="po-detail-items-title-row">
                    <span className="po-detail-items-heading">LINE ITEMS</span>
                    <span className="po-detail-items-count">{details.length}</span>
                  </div>
                  {details.length === 0 ? (
                    <p className="po-detail-no-items">No items recorded for this PO.</p>
                  ) : (
                    <table className="po-detail-table">
                      <thead>
                        <tr><th>#</th><th>PRODUCT</th><th>TYPE</th><th>DETAILS</th><th>QTY</th><th>UNIT PRICE</th><th>SUBTOTAL</th></tr>
                      </thead>
                      <tbody>
                        {details.map((item, idx) => {
                          const ab = item.approvedBy || item.approved_by || null;
                          const detailApprovedBy = ab ? `${ab.first_name || ''} ${ab.last_name || ''}`.trim() || ab.username || '—' : '—';
                          const pType = item.product_type || item.product?.product_type || '';
                          return (
                            <tr key={item.po_detail_id ?? idx}>
                              <td className="po-detail-table-idx">{idx + 1}</td>
                              <td>
                                <div>{item.product_name || item.product?.product_name || `Item ${idx + 1}`}</div>
                                {(item.product_code || item.product?.product_code) && (
                                  <small style={{ color: '#94a3b8' }}>{item.product_code || item.product?.product_code}</small>
                                )}
                                {ab && <span style={{ display: 'block', fontSize: 11, color: '#15803d' }}>✓ Approved by: {detailApprovedBy}</span>}
                              </td>
                              <td style={{ fontSize: 11 }}>
                                <span className={`po-type-badge ${pType === 'consumable' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                                  {pType === 'consumable' ? 'CONSUMABLE' : 'APPLIANCE'}
                                </span>
                              </td>
                              <td style={{ fontSize: 12, color: '#64748b' }}>{poLineLabel(item)}</td>
                              <td>{item.quantity_ordered}</td>
                              <td>₱{Number(item.unit_price || 0).toLocaleString('en-PH')}</td>
                              <td className="po-detail-table-sub">₱{Number(item.subtotal || 0).toLocaleString('en-PH')}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  )}
                </div>

                <div className="po-detail-total">
                  <span className="po-detail-total-label">TOTAL ESTIMATED VALUE</span>
                  <span className="po-detail-total-value">₱{amount.toLocaleString('en-PH')}</span>
                </div>
              </div>

              {(() => {
                const poPayload = {
                  po_number: d.po_number,
                  status:    statusCfg.label,
                  supplier: { name: d.supplier?.supplier_name, address: d.supplier?.address, origin: d.supplier?.origin, region: d.supplier?.region, contact_person: d.supplier?.contact_person, contact_number: d.supplier?.contact_number, email: d.supplier?.email, tin: d.supplier?.tin },
                  ship_to:  { name: d.location?.location_name, address: [d.location?.address, d.location?.city, d.location?.province, d.location?.region].filter(Boolean).join(', ') || null, contact_number: d.location?.contact_number, email: d.location?.email, manager_name: d.location?.manager_name },
                  prepared_by:            createdByName,
                  approved_by:            actorName !== '—' ? actorName : null,
                  order_date:             d.order_date ? String(d.order_date).slice(0, 10) : '—',
                  expected_delivery_date: d.expected_delivery_date ? String(d.expected_delivery_date).slice(0, 10) : '—',
                  items: details.map(item => ({ name: item.product_name || item.product?.product_name || '—', unit: item.unit_name || item.product?.unit?.unit_name || '—', qty: item.quantity_ordered, unit_price: item.unit_price, subtotal: item.subtotal })),
                  total_amount: amount,
                };
                return (
                  <div className="po-detail-footer">
                    <button type="button" className="po-detail-btn po-detail-btn--excel" onClick={() => exportPOToExcel(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                      EXPORT EXCEL
                    </button>
                    <button type="button" className="po-detail-btn po-detail-btn--doc" onClick={() => exportPOToDocument(poPayload)}>
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      EXPORT DOCUMENT
                    </button>
                  </div>
                );
              })()}
            </div>
          );
        })()}
      </Modal>
    </AdminLayout>
  );
};

export default PurchaseOrderPage;
