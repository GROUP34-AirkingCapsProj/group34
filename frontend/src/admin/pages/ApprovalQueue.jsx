import React, { useEffect, useMemo, useState, useCallback } from 'react';
import { useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { useAuth } from '../context/AuthContext';
import { canActOnApprovalQueue } from '../utils/roles';
import { purchaseOrdersAPI, statusAPI, productsAPI, transferRequestsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/approval_queue.css';
import '../styles/po_recommendation.css';
import { exportPOToExcel, exportPOToDocument } from '../utils/poExport';

const AQ_TABS = ['purchase-orders', 'branch-transfers'];

const formatYmd = (value) => {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

const daysUntil = (value) => {
  if (!value) return null;
  const exp = new Date(value);
  if (Number.isNaN(exp.getTime())) return null;
  const today = new Date();
  exp.setHours(0, 0, 0, 0);
  today.setHours(0, 0, 0, 0);
  return Math.ceil((exp.getTime() - today.getTime()) / 86400000);
};

const formatDateTime = (value) => {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const ApprovalQueue = ({ initialTab } = {}) => {
  const { user } = useAuth();
  const canActOnQueue = useMemo(() => canActOnApprovalQueue(user), [user]);
  const location = useLocation();
const [activeTab, setActiveTab] = useState(() => {
    if (typeof window === 'undefined') return 'purchase-orders';
    const q = new URLSearchParams(window.location.search).get('tab');
    if (q && AQ_TABS.includes(q)) return q;
    if (initialTab && AQ_TABS.includes(initialTab)) return initialTab;
    return 'purchase-orders';
  });

  useEffect(() => {
    const q = new URLSearchParams(location.search).get('tab');
    if (q && AQ_TABS.includes(q)) {
      setActiveTab(q);
      return;
    }
    if (location.pathname === '/admin/purchase-orders' && initialTab && AQ_TABS.includes(initialTab)) {
      setActiveTab(initialTab);
    }
  }, [location.search, location.pathname, initialTab]);

  const [purchaseOrders, setPurchaseOrders] = useState([]);
  const [serverPOLoading, setServerPOLoading] = useState(false);

  // Fetch ALL POs from the server (pending, authorized, rejected, cancelled, completed)
  const fetchServerPOs = useCallback(async () => {
    setServerPOLoading(true);
    try {
      const res = await purchaseOrdersAPI.getAll({ per_page: 100 });
      const rows = res?.data?.data || res?.data || [];
      const serverPOs = rows.map((po) => {
        const serverStatus = String(po.status?.status_name || '').toLowerCase();
        const mappedStatus = serverStatus.includes('authori')
          ? 'authorized'
          : serverStatus.includes('reject')
          ? 'rejected'
          : serverStatus.includes('cancel')
          ? 'cancelled'
          : serverStatus.includes('complet')
          ? 'completed'
          : serverStatus.includes('partial')
          ? 'partial'
          : 'pending';

        return {
          id: `server-${po.po_id}`,
          po_number: po.po_number,
          supplier: po.supplier?.supplier_name || po.supplier?.name || '—',
          amount: Number(po.total_amount || po.grand_total || 0),
          prepared_by: po.created_by && typeof po.created_by === 'object'
            ? `${po.created_by.first_name || ''} ${po.created_by.last_name || ''}`.trim() || po.created_by.username || po.created_by.email || '—'
            : '—',
          approved_by_name: po.approved_by && typeof po.approved_by === 'object'
            ? `${po.approved_by.first_name || ''} ${po.approved_by.last_name || ''}`.trim() || po.approved_by.username || po.approved_by.email || null
            : null,
          date: po.created_at
            ? new Date(po.created_at).toLocaleString('en-PH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
              })
            : '—',
          expected_delivery_date: po.expected_delivery_date,
          status: mappedStatus,
          synced_to_api: true,
          backend_po_id: po.po_id,
          supplier_obj: po.supplier || null,
          location_obj: po.location || null,
          approved_by_obj: po.approved_by && typeof po.approved_by === 'object' ? po.approved_by : null,
          created_by_obj: po.created_by && typeof po.created_by === 'object' ? po.created_by : null,
          line_items_snapshot: Array.isArray(po.details)
            ? po.details.map((d) => ({
                product: d.product?.product_name || d.product?.name || `Product #${d.product_id}`,
                product_id: d.product_id,
                qty: d.quantity_ordered,
                unit_cost: d.unit_price,
                total: d.subtotal,
                type: d.product?.product_type || null,
                brand: d.product?.brand?.brand_name || null,
                category: d.product?.category?.category_name || null,
                approved_by: d.approved_by && typeof d.approved_by === 'object'
                  ? `${d.approved_by.first_name || ''} ${d.approved_by.last_name || ''}`.trim() || d.approved_by.username || null
                  : null,
              }))
            : [],
        };
      });

// Use ONLY server POs - no localStorage merge
      setPurchaseOrders(serverPOs);
    } catch (err) {
      console.error('Failed to fetch server POs:', err);
    } finally {
      setServerPOLoading(false);
    }
  }, []);


  // Keep server POs fresh while the purchase-orders tab is visible.
  useEffect(() => {
    if (activeTab !== 'purchase-orders') return undefined;

    fetchServerPOs();
    const interval = window.setInterval(fetchServerPOs, 15000);
    window.addEventListener('approval-notifications-updated', fetchServerPOs);
    window.addEventListener('storage', fetchServerPOs);
    window.addEventListener('inventory-scan-applied', fetchServerPOs);

    return () => {
      window.clearInterval(interval);
      window.removeEventListener('approval-notifications-updated', fetchServerPOs);
      window.removeEventListener('storage', fetchServerPOs);
      window.removeEventListener('inventory-scan-applied', fetchServerPOs);
    };
  }, [activeTab, fetchServerPOs]);

  // ── Stock Transfer state ───────────────────────────────────────────────────
  const [pendingTransfers,      setPendingTransfers]      = useState([]);
  const [loadingTransfers,      setLoadingTransfers]      = useState(false);
  const [transferDetail,        setTransferDetail]        = useState(null);
  const [transferDetailLoading, setTransferDetailLoading] = useState(false);
  const [transferApprovedQtys,  setTransferApprovedQtys]  = useState({});
  const [transferActionLoading, setTransferActionLoading] = useState(null);
  const [transferRejectModal,   setTransferRejectModal]   = useState({ open: false, id: null, reason: '', submitting: false });


  const [poActionLoading, setPoActionLoading] = useState(null);
  const [poVerifyModal, setPoVerifyModal] = useState(null);
  const [poVerifyLines, setPoVerifyLines] = useState([]);
  const [poVerifyLoading, setPoVerifyLoading] = useState(false);
  const [poVerifyError, setPoVerifyError] = useState('');
  const [poVerifySupplier, setPoVerifySupplier]     = useState(null);
  const [poVerifyLocation, setPoVerifyLocation]     = useState(null);
  const [poVerifyApprovedBy, setPoVerifyApprovedBy] = useState(null);
  const [poVerifyCreatedBy, setPoVerifyCreatedBy]   = useState(null);

  useEffect(() => {
    if (!poVerifyModal) {
      setPoVerifyLines([]);
      setPoVerifyError('');
      setPoVerifyLoading(false);
      setPoVerifySupplier(null);
      setPoVerifyLocation(null);
      setPoVerifyApprovedBy(null);
      setPoVerifyCreatedBy(null);
      return;
    }

    // cancelled = true when this effect re-runs (new PO opened) or modal closes.
    // Prevents a slow fetch from a previously-opened PO from overwriting the current PO's data.
    let cancelled = false;

    const po = poVerifyModal;
    const fromSnapshot = Array.isArray(po.line_items_snapshot) ? po.line_items_snapshot : [];
    if (fromSnapshot.length) {
      // Show lines immediately from snapshot — no network wait
      setPoVerifyLines(
        fromSnapshot.map((row, i) => ({
          key: `snap-${i}`,
          name: row.product || `Product #${row.product_id ?? '—'}`,
          product_id: row.product_id,
          qty: row.qty,
          unit: row.unit_cost,
          lineTotal: row.total,
          type: row.type,
          brand: row.brand,
          category: row.category,
          approved_by: row.approved_by || null,
        })),
      );
      setPoVerifyLoading(false);

      // Seed header fields immediately from list data (instant, no network)
      if (po.supplier_obj) setPoVerifySupplier(po.supplier_obj);
      if (po.location_obj) setPoVerifyLocation(po.location_obj);
      if (po.approved_by_obj) setPoVerifyApprovedBy(po.approved_by_obj);
      if (po.created_by_obj) setPoVerifyCreatedBy(po.created_by_obj);

      // Background fetch for richer header fields (phone, address, etc.)
      if (po.backend_po_id != null && po.backend_po_id !== '') {
        (async () => {
          try {
            const res = await purchaseOrdersAPI.getById(String(po.backend_po_id));
            if (cancelled) return;
            const data = res?.data;
            if (data?.supplier) setPoVerifySupplier(data.supplier);
            if (data?.location) setPoVerifyLocation(data.location);
            if (data?.approved_by && typeof data.approved_by === 'object') setPoVerifyApprovedBy(data.approved_by);
            if (data?.created_by && typeof data.created_by === 'object')  setPoVerifyCreatedBy(data.created_by);
          } catch (_) { /* snapshot already shown — ignore */ }
        })();
      }
      return () => { cancelled = true; };
    }

    const payloadDetails = po.api_create_payload?.details;
    if (Array.isArray(payloadDetails) && payloadDetails.length) {
      setPoVerifyLoading(true);
      setPoVerifyError('');
      (async () => {
        try {
          const rows = await Promise.all(
            payloadDetails.map(async (d, i) => {
              const pid = d.product_id;
              let name = `Product #${pid}`;
              try {
                const res = await productsAPI.getById(pid);
                const p = res?.data;
                name = p?.product_name || p?.name || name;
              } catch {
                /* keep fallback label */
              }
              return {
                key: `pay-${i}`,
                name,
                product_id: pid,
                qty: d.quantity_ordered,
                unit: d.unit_price,
                lineTotal: d.subtotal,
              };
            }),
          );
          if (cancelled) return;
          setPoVerifyLines(rows);
        } catch {
          if (cancelled) return;
          setPoVerifyError('Could not load product names for this PO.');
          setPoVerifyLines(
            payloadDetails.map((d, i) => ({
              key: `pay-${i}`,
              name: `Product #${d.product_id}`,
              product_id: d.product_id,
              qty: d.quantity_ordered,
              unit: d.unit_price,
              lineTotal: d.subtotal,
            })),
          );
        } finally {
          if (!cancelled) setPoVerifyLoading(false);
        }
      })();
      return () => { cancelled = true; };
    }

    const backendId = po.backend_po_id;
    if (backendId != null && backendId !== '') {
      setPoVerifyLoading(true);
      setPoVerifyError('');
      (async () => {
        try {
          const res = await purchaseOrdersAPI.getById(String(backendId));
          if (cancelled) return;
          const data = res?.data;
          const details = Array.isArray(data?.details) ? data.details : [];
          if (data?.supplier) setPoVerifySupplier(data.supplier);
          if (data?.location) setPoVerifyLocation(data.location);
          if (data?.approved_by && typeof data.approved_by === 'object') setPoVerifyApprovedBy(data.approved_by);
          if (data?.created_by && typeof data.created_by === 'object')  setPoVerifyCreatedBy(data.created_by);
          setPoVerifyLines(
            details.map((d, i) => {
              const p = d.product || {};
              const ab = (d.approved_by && typeof d.approved_by === 'object') ? d.approved_by : null;
              return {
                key: `api-${d.detail_id ?? i}`,
                name: p.product_name || p.name || `Product #${d.product_id}`,
                product_id: d.product_id,
                qty: d.quantity_ordered,
                unit: d.unit_price,
                lineTotal: d.subtotal,
                approved_by: ab
                  ? `${ab.first_name || ''} ${ab.last_name || ''}`.trim() || ab.username || null
                  : null,
              };
            }),
          );
        } catch (err) {
          if (cancelled) return;
          setPoVerifyError(err?.message || 'Could not load PO lines from the server.');
          setPoVerifyLines([]);
        } finally {
          if (!cancelled) setPoVerifyLoading(false);
        }
      })();
      return () => { cancelled = true; };
    }

    setPoVerifyLines([]);
    setPoVerifyError('No line items are stored for this PO. Older sample/local entries may not include a product list.');
    setPoVerifyLoading(false);
  }, [poVerifyModal]);


const handlePOAction = async (id, action) => {
    const nextStatus = action === 'approve' ? 'authorized' : 'rejected';

    // For reject action, also update the server with rejected status
    if (action === 'reject') {
      const po = purchaseOrders.find((p) => p.id === id);
      if (!po?.backend_po_id) return;

      setPoActionLoading(id);
      try {
        const stRes = await statusAPI.getAll({ category: 'purchase_order' });
        const stRows = Array.isArray(stRes?.data) ? stRes.data : [];
        const rejectedId = stRows.find((s) => {
          const n = String(s.status_name || '').toLowerCase();
          return n === 'rejected' || n.includes('reject');
        })?.status_id;
        if (!rejectedId) {
          toast.error('No Rejected status for purchase orders. Run backend migrations, then try again.');
          return;
        }
        const currentUserId = user?.user_id ?? null;
        await purchaseOrdersAPI.update(String(po.backend_po_id), {
          status_id: rejectedId,
          ...(currentUserId ? { approved_by: currentUserId } : {}),
        });
        setPurchaseOrders((prev) =>
          prev.map((p) => (p.id === id ? { ...p, status: 'rejected' } : p)),
        );
        toast.success('PO rejected on the server.');
      } catch (err) {
        if (err?.status === 403) {
          toast.error('This account cannot update purchase orders on the server.');
          return;
        }
        const msg = err?.errors
          ? Object.values(err.errors).flat().join(' ')
          : err?.message || 'Could not reject this PO on the server.';
        toast.error(msg);
      } finally {
        setPoActionLoading(null);
      }
      return;
    }

    const po = purchaseOrders.find((p) => p.id === id);
    if (!po?.backend_po_id) return;

    setPoActionLoading(id);
    try {
      const stRes = await statusAPI.getAll({ category: 'purchase_order' });
      const stRows = Array.isArray(stRes?.data) ? stRes.data : [];
      const authorizedId = stRows.find((s) => {
        const n = String(s.status_name || '').toLowerCase();
        return n === 'authorized' || n.includes('authori');
      })?.status_id;
      if (!authorizedId) {
        toast.error('No Authorized status for purchase orders. Run backend migrations, then try again.');
        return;
      }
      const currentUserId = user?.user_id ?? null;
      await purchaseOrdersAPI.update(String(po.backend_po_id), {
        status_id: authorizedId,
        ...(currentUserId ? { approved_by: currentUserId } : {}),
      });
      const patch = {
        status: 'authorized',
        approved_at: new Date().toISOString(),
        approved_by_name: user
          ? `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.username || null
          : null,
        approved_by_obj: user ?? null,
      };
      setPurchaseOrders((prev) =>
        prev.map((p) => (p.id === id ? { ...p, ...patch } : p)),
      );
      toast.success('PO authorized on the server.');
    } catch (err) {
      if (err?.status === 403) {
        toast.error('This account cannot update purchase orders on the server.');
        return;
      }
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Could not authorize this PO on the server.';
      toast.error(msg);
    } finally {
      setPoActionLoading(null);
    }
  };

  // ── Stock Transfer fetch ───────────────────────────────────────────────────
  const fetchPendingTransfers = useCallback(async () => {
    setLoadingTransfers(true);
    try {
      const res = await transferRequestsAPI.getAll({ per_page: 100 });
      const all = Array.isArray(res?.data) ? res.data : [];
      setPendingTransfers(all.filter(x => x.status?.status_name === 'Pending'));
    } catch {
      toast.error('Could not load pending stock transfers.');
    } finally {
      setLoadingTransfers(false);
    }
  }, []);

  useEffect(() => {
    if (activeTab === 'branch-transfers') fetchPendingTransfers();
  }, [activeTab, fetchPendingTransfers]);

  const openTransferDetail = useCallback(async (r) => {
    setTransferDetail(r);
    setTransferApprovedQtys({});
    setTransferDetailLoading(true);
    try {
      const res = await transferRequestsAPI.getById(r.transfer_id);
      const full = res?.data ?? r;
      setTransferDetail(full);
      const init = {};
      (full.details ?? []).forEach(d => { init[d.transfer_detail_id] = d.quantity_requested; });
      setTransferApprovedQtys(init);
    } catch { /* keep card-level data */ }
    finally { setTransferDetailLoading(false); }
  }, []);

  const closeTransferDetail = useCallback(() => { setTransferDetail(null); setTransferApprovedQtys({}); }, []);

  const handleTransferApprove = useCallback(async (id) => {
    setTransferActionLoading(id);
    try {
      const quantities = Object.entries(transferApprovedQtys).map(([detailId, qty]) => ({
        transfer_detail_id: Number(detailId),
        quantity_approved:  Math.max(1, parseInt(qty) || 1),
      }));
      await transferRequestsAPI.approve(id, { quantities });
      setPendingTransfers(p => p.filter(r => r.transfer_id !== id));
      closeTransferDetail();
      toast.success('Transfer request approved.');
    } catch (err) {
      toast.error(err?.message || 'Approval failed.');
    } finally {
      setTransferActionLoading(null);
    }
  }, [transferApprovedQtys, closeTransferDetail]);

  const handleTransferReject = useCallback(async () => {
    if (!transferRejectModal.reason.trim()) return;
    setTransferRejectModal(p => ({ ...p, submitting: true }));
    try {
      await transferRequestsAPI.reject(transferRejectModal.id, { rejection_reason: transferRejectModal.reason });
      setPendingTransfers(p => p.filter(r => r.transfer_id !== transferRejectModal.id));
      setTransferRejectModal({ open: false, id: null, reason: '', submitting: false });
      closeTransferDetail();
      toast.success('Transfer request rejected.');
    } catch (err) {
      toast.error(err?.message || 'Rejection failed.');
      setTransferRejectModal(p => ({ ...p, submitting: false }));
    }
  }, [transferRejectModal, closeTransferDetail]);

  const trLocName = (r) => ({
    from: r.from_location?.location_name ?? r.fromLocation?.location_name ?? '—',
    to:   r.to_location?.location_name   ?? r.toLocation?.location_name   ?? '—',
  });

return (
    <AdminLayout>
      <div className="aq-content">
        {/* Page Header */}
        <div className="aq-page-header">
          <h1>Approvals</h1>
          {!canActOnQueue && (
            <p className="aq-viewonly-banner">
              <strong>View only.</strong> Only <strong>Admin</strong> and <strong>Branch Manager</strong> can authorize
              purchase orders and restock here. You can review and open PO line items with the eye icon.
            </p>
          )}
          <p>
            {canActOnQueue ? (
              <>
                <strong>Approve</strong> sets a purchase order to <strong>Authorized</strong> on the server. Receiving
                stays blocked until authorized and on or after the expected delivery date when set.
              </>
            ) : (
              <>
                <strong>Admin</strong> or <strong>Branch Manager</strong> must <strong>Authorize</strong> a purchase
                order before receiving can proceed (expected delivery date rules still apply).
              </>
            )}
          </p>
        </div>

        {/* Tab Navigation */}
        <div className="aq-tabs">
          <button
            type="button"
            className={`aq-tab-btn ${activeTab === 'purchase-orders' ? 'active' : ''}`}
            onClick={() => setActiveTab('purchase-orders')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            Purchase orders
          </button>
          <button
            type="button"
            className={`aq-tab-btn ${activeTab === 'branch-transfers' ? 'active' : ''}`}
            onClick={() => setActiveTab('branch-transfers')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="17 1 21 5 17 9"></polyline>
              <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
              <polyline points="7 23 3 19 7 15"></polyline>
              <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
            </svg>
            Stock Transfer
          </button>
        </div>

        {/* Purchase Orders Tab */}
        {activeTab === 'purchase-orders' && (
          <div className="aq-list">
            {purchaseOrders.length === 0 ? (
              <div className="aq-empty-state">
                <div className="aq-empty-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                  </svg>
                </div>
                <p>No purchase orders found.</p>
              </div>
            ) : (
              purchaseOrders.map(po => (
                <div className="aq-card" key={po.id}>
                <div className="aq-card-top">
                  <div className="aq-card-title">
                    <h3>PO: {po.po_number}</h3>
                    <p>Supplier: {po.supplier}</p>
                  </div>
                </div>
                <div className="aq-card-bottom">
                  <div className="aq-card-info">
                    <span className="aq-amount">₱{Number(po.amount ?? 0).toLocaleString('en-PH')}</span>
                    <span className="aq-meta">Prepared by: {po.prepared_by} • {po.date}</span>
                    <span className="aq-meta" style={{ display: 'block', marginTop: 6 }}>
                      Expected arrival: <strong>{formatYmd(po.expected_delivery_date)}</strong>
                      {po.expected_delivery_date && daysUntil(po.expected_delivery_date) != null && (
                        <>
                          {' '}
                          ({daysUntil(po.expected_delivery_date) > 0
                            ? `in ${daysUntil(po.expected_delivery_date)} day(s)`
                            : daysUntil(po.expected_delivery_date) === 0
                              ? 'due today'
                              : `${Math.abs(daysUntil(po.expected_delivery_date))} day(s) overdue`})
                        </>
                      )}
                    </span>
                    {po.status === 'authorized' && (
                      <span className="aq-meta" style={{ display: 'block', marginTop: 4, color: '#15803d' }}>
                        Approved{po.approved_at ? ` • ${formatYmd(po.approved_at)}` : ''}.
                        {po.synced_to_api
                          ? ' In the database — listed in Receive PO (subject to expected delivery date).'
                          : ' Local-only — not in Receive PO until the PO exists in the database.'}
                      </span>
                    )}
                    {po.status === 'rejected' && (
                      <span className="aq-meta" style={{ display: 'block', marginTop: 4, color: '#dc2626' }}>
                        Rejected. This PO cannot proceed to receiving.
                        {po.api_sync_note}
                      </span>
                    )}
                  </div>
                  <div className="aq-card-actions">
                    <button
                      type="button"
                      className="aq-btn-view"
                      title="View PO line items for verification"
                      aria-label="View PO products"
                      onClick={() => setPoVerifyModal(po)}
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                      </svg>
                    </button>
                    {/* ✅ STATUS BADGES */}
{po.status === 'authorized' && (
  <span className="aq-badge authorized">AUTHORIZED</span>
)}

{po.status === 'rejected' && (
  <span className="aq-badge rejected">REJECTED</span>
)}

{po.status === 'cancelled' && (
  <span className="aq-badge inactive">CANCELLED</span>
)}

{po.status === 'completed' && (
  <span className="aq-badge completed">COMPLETED</span>
)}

{po.status === 'partial' && (
  <span className="aq-badge partial">PARTIALLY RECEIVED</span>
)}

{/* ✅ ONLY PENDING HAS ACTIONS */}
{po.status === 'pending' && (
  canActOnQueue ? (
    <>
      <button
        type="button"
        className="aq-btn-reject"
        disabled={poActionLoading === po.id}
        onClick={() => handlePOAction(po.id, 'reject')}
      >
        REJECT
      </button>

      <button
        type="button"
        className="aq-btn-approve"
        disabled={poActionLoading === po.id}
        onClick={() => handlePOAction(po.id, 'approve')}
      >
        {poActionLoading === po.id ? 'SAVING…' : 'APPROVE'}
      </button>
    </>
  ) : (
    <span className="aq-badge view-only">PENDING — view only</span>
  )
)}
                  </div>
                </div>
              </div>
            ))
          )}
          </div>
        )}

{/* Stock Transfer Tab */}
        {activeTab === 'branch-transfers' && (
          <div className="aq-list">
            {loadingTransfers ? (
              <p className="aq-meta" style={{ padding: 24 }}>Loading pending transfers…</p>
            ) : pendingTransfers.length === 0 ? (
              <div className="aq-empty-state">
                <div className="aq-empty-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <polyline points="17 1 21 5 17 9"/>
                    <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                    <polyline points="7 23 3 19 7 15"/>
                    <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                  </svg>
                </div>
                <p>No stock transfers pending approval.</p>
              </div>
            ) : (
              pendingTransfers.map(r => {
                const { from, to } = trLocName(r);
                const itemCount = r.details?.length ?? 0;
                return (
                  <div className="aq-card" key={r.transfer_id}>
                    <div className="aq-card-top">
                      <div className="aq-card-title">
                        <h3>TR: {r.transfer_number}</h3>
                        <p>{from} → {to}</p>
                      </div>
                    </div>
                    <div className="aq-card-bottom">
                      <div className="aq-card-info">
                        <span className="aq-meta">
                          Transfer date: <strong>{formatYmd(r.transfer_date)}</strong>
                        </span>
                        <span className="aq-meta" style={{ display: 'block', marginTop: 4 }}>
                          Submitted {formatDateTime(r.created_at)}
                          {r.requestedBy?.first_name || r.created_by_user?.first_name
                            ? ` · ${[r.requestedBy?.first_name ?? r.created_by_user?.first_name, r.requestedBy?.last_name ?? r.created_by_user?.last_name].filter(Boolean).join(' ')}`
                            : ''}
                        </span>
                        {itemCount > 0 && (
                          <span className="aq-meta" style={{ display: 'block', marginTop: 4 }}>
                            {itemCount} item{itemCount !== 1 ? 's' : ''}
                          </span>
                        )}
                      </div>
                      <div className="aq-card-actions">
                        <button
                          type="button"
                          className="aq-btn-view"
                          title="View transfer details"
                          onClick={() => openTransferDetail(r)}
                        >
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                          </svg>
                        </button>
                        {canActOnQueue ? (
                          <>
                            <button
                              type="button"
                              className="aq-btn-reject"
                              disabled={transferActionLoading === r.transfer_id}
                              onClick={() => setTransferRejectModal({ open: true, id: r.transfer_id, reason: '', submitting: false })}
                            >
                              REJECT
                            </button>
                            <button
                              type="button"
                              className="aq-btn-approve"
                              disabled={transferActionLoading === r.transfer_id}
                              onClick={() => openTransferDetail(r)}
                            >
                              {transferActionLoading === r.transfer_id ? 'SAVING…' : 'APPROVE'}
                            </button>
                          </>
                        ) : (
                          <span className="aq-badge view-only">PENDING — view only</span>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        )}
      </div>

      {/* Stock Transfer Detail / Approve Modal */}
      {transferDetail && (
        <Modal
          title={`Transfer Request — ${transferDetail.transfer_number}`}
          onClose={closeTransferDetail}
          maxWidth={560}
        >
          {transferDetailLoading ? (
            <p style={{ color: '#6b7280', fontSize: 13 }}>Loading details…</p>
          ) : (
            <>
              <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 12 }}>
                <strong>{trLocName(transferDetail).from}</strong>
                {' → '}
                <strong>{trLocName(transferDetail).to}</strong>
                {transferDetail.transfer_date && (
                  <span style={{ marginLeft: 10 }}>· {formatYmd(transferDetail.transfer_date)}</span>
                )}
              </div>

              {(transferDetail.details ?? []).length === 0 ? (
                <p style={{ fontSize: 13, color: '#9ca3af' }}>No line items.</p>
              ) : (
                <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse', marginBottom: 16 }}>
                  <thead>
                    <tr style={{ borderBottom: '1px solid #e5e7eb', color: '#6b7280', textAlign: 'left' }}>
                      <th style={{ padding: '6px 8px', fontWeight: 600 }}>Product</th>
                      <th style={{ padding: '6px 8px', fontWeight: 600, textAlign: 'right' }}>Requested</th>
                      {canActOnQueue && <th style={{ padding: '6px 8px', fontWeight: 600, textAlign: 'right' }}>Approve Qty</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {(transferDetail.details ?? []).map(d => (
                      <tr key={d.transfer_detail_id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                        <td style={{ padding: '8px 8px' }}>
                          {d.product?.product_name ?? d.product_name ?? `#${d.product_id}`}
                        </td>
                        <td style={{ padding: '8px 8px', textAlign: 'right' }}>
                          {d.quantity_requested}
                        </td>
                        {canActOnQueue && (
                          <td style={{ padding: '8px 8px', textAlign: 'right' }}>
                            <input
                              type="number"
                              min={1}
                              max={d.quantity_requested}
                              value={transferApprovedQtys[d.transfer_detail_id] ?? d.quantity_requested}
                              onChange={e => setTransferApprovedQtys(p => ({ ...p, [d.transfer_detail_id]: e.target.value }))}
                              style={{ width: 70, padding: '4px 6px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, textAlign: 'right' }}
                            />
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}

              {canActOnQueue && (
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
                  <button
                    type="button"
                    className="aq-modal-done"
                    onClick={() => setTransferRejectModal({ open: true, id: transferDetail.transfer_id, reason: '', submitting: false })}
                  >
                    Reject
                  </button>
                  <button
                    type="button"
                    className="aq-btn-approve-restock"
                    disabled={transferActionLoading === transferDetail.transfer_id}
                    onClick={() => handleTransferApprove(transferDetail.transfer_id)}
                    style={{ border: 'none', cursor: 'pointer' }}
                  >
                    {transferActionLoading === transferDetail.transfer_id ? 'Approving…' : 'Approve Transfer'}
                  </button>
                </div>
              )}
            </>
          )}
        </Modal>
      )}

      {/* Stock Transfer Reject Modal */}
      {transferRejectModal.open && (
        <Modal
          title="Reject Transfer Request"
          onClose={() => !transferRejectModal.submitting && setTransferRejectModal({ open: false, id: null, reason: '', submitting: false })}
          maxWidth={420}
        >
          <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 12 }}>
            Please provide a reason for rejecting this transfer request.
          </p>
          <textarea
            rows={3}
            style={{ width: '100%', padding: '8px 10px', border: '1px solid #e5e7eb', borderRadius: 6, fontSize: 13, resize: 'vertical' }}
            placeholder="Rejection reason…"
            value={transferRejectModal.reason}
            onChange={e => setTransferRejectModal(p => ({ ...p, reason: e.target.value }))}
          />
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button
              type="button"
              className="aq-modal-done"
              disabled={transferRejectModal.submitting}
              onClick={() => setTransferRejectModal({ open: false, id: null, reason: '', submitting: false })}
            >
              Cancel
            </button>
            <button
              type="button"
              className="aq-btn-reject"
              disabled={transferRejectModal.submitting || !transferRejectModal.reason.trim()}
              onClick={handleTransferReject}
              style={{ border: 'none', cursor: 'pointer' }}
            >
              {transferRejectModal.submitting ? 'Rejecting…' : 'Confirm Reject'}
            </button>
          </div>
        </Modal>
      )}

      {poVerifyModal && (() => {
        const po = poVerifyModal;
        const statusMap = {
          pending:    { label: 'PENDING APPROVAL',   color: 'pending' },
          authorized: { label: 'APPROVED',           color: 'approved' },
          partial:    { label: 'PARTIALLY RECEIVED', color: 'partial' },
          completed:  { label: 'COMPLETED',          color: 'completed' },
          rejected:   { label: 'REJECTED',           color: 'rejected' },
          cancelled:  { label: 'CANCELLED',          color: 'cancelled' },
        };
        const statusCfg = statusMap[po.status] || { label: (po.status || 'DRAFT').toUpperCase(), color: 'draft' };
        const amount = Number(po.amount || 0);
        const itemCount = poVerifyLines.length || (Array.isArray(po.line_items_snapshot) ? po.line_items_snapshot.length : 0);
        const priColor = amount >= 1000000 || itemCount >= 4 ? 'high' : amount >= 500000 || itemCount >= 2 ? 'medium' : 'low';
        const priLabel = priColor === 'high' ? 'HIGH' : priColor === 'medium' ? 'MEDIUM' : 'LOW';

        return (
          <div className="aq-modal-backdrop" role="presentation" onClick={() => setPoVerifyModal(null)}>
            <div className="po-detail-modal po-detail-modal--aq" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>

              {/* ── Gradient header ── */}
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
                  <h2 className="po-detail-head-number">{po.po_number}</h2>
                  <p className="po-detail-head-sub">
                    {po.supplier || '—'} &nbsp;·&nbsp; {po.date || '—'}
                  </p>
                </div>
                <div className="po-detail-head-badges">
                  <span className={`po-detail-status-pill po-detail-status-pill--${statusCfg.color}`}>{statusCfg.label}</span>
                  <span className={`po-detail-priority-pill po-detail-priority-pill--${priColor}`}>{priLabel}</span>
                </div>
                <button type="button" className="po-detail-head-close" onClick={() => setPoVerifyModal(null)} aria-label="Close">×</button>
              </div>

              {/* ── Status strip ── */}
              <div className={`po-detail-strip po-detail-strip--${statusCfg.color}`}>
                {statusCfg.color === 'pending'   && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>}
                {statusCfg.color === 'approved'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>}
                {statusCfg.color === 'partial'   && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M12 22C6.48 22 2 17.52 2 12S6.48 2 12 2s10 4.48 10 10"/><polyline points="12 6 12 12 16 14"/><path d="M16 16h6v6"/></svg>}
                {statusCfg.color === 'completed' && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>}
                {statusCfg.color === 'rejected'  && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>}
                {statusCfg.color === 'cancelled' && <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>}
                <span><strong>{statusCfg.label}</strong></span>
              </div>

              {/* ── Info grid ── */}
              <div className="po-detail-body">
                {/* Address blocks */}
                <div className="po-detail-address-grid">
                  <div className="po-detail-address-block">
                    <div className="po-detail-address-block-title">Supplier / Vendor</div>
                    <div className="po-detail-address-row">
                      <span className="po-detail-address-label">Name</span>
                      <span className="po-detail-address-value po-detail-address-value--name">
                        {poVerifySupplier?.supplier_name || po.supplier || '—'}
                      </span>
                    </div>
                    {poVerifySupplier?.address && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Address</span>
                        <span className="po-detail-address-value">{poVerifySupplier.address}</span>
                      </div>
                    )}
                    {(poVerifySupplier?.origin || poVerifySupplier?.region) && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Origin / Region</span>
                        <span className="po-detail-address-value">{[poVerifySupplier.origin, poVerifySupplier.region].filter(Boolean).join(', ')}</span>
                      </div>
                    )}
                    {poVerifySupplier?.contact_person && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Contact Person</span>
                        <span className="po-detail-address-value">{poVerifySupplier.contact_person}</span>
                      </div>
                    )}
                    {poVerifySupplier?.contact_number && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Phone</span>
                        <span className="po-detail-address-value">{poVerifySupplier.contact_number}</span>
                      </div>
                    )}
                    {poVerifySupplier?.email && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Email</span>
                        <span className="po-detail-address-value">{poVerifySupplier.email}</span>
                      </div>
                    )}
                    {poVerifySupplier?.tin && (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">TIN</span>
                        <span className="po-detail-address-value">{poVerifySupplier.tin}</span>
                      </div>
                    )}
                  </div>
                  <div className="po-detail-address-block">
                    <div className="po-detail-address-block-title">Ship To / Deliver To</div>
                    {poVerifyLocation ? (
                      <>
                        <div className="po-detail-address-row">
                          <span className="po-detail-address-label">Branch / Location</span>
                          <span className="po-detail-address-value po-detail-address-value--name">{poVerifyLocation.location_name}</span>
                        </div>
                        {(poVerifyLocation.address || poVerifyLocation.city || poVerifyLocation.province || poVerifyLocation.region) && (
                          <div className="po-detail-address-row">
                            <span className="po-detail-address-label">Address</span>
                            <span className="po-detail-address-value">
                              {[poVerifyLocation.address, poVerifyLocation.city, poVerifyLocation.province, poVerifyLocation.region].filter(Boolean).join(', ')}
                            </span>
                          </div>
                        )}
                        {poVerifyLocation.contact_number && (
                          <div className="po-detail-address-row">
                            <span className="po-detail-address-label">Phone</span>
                            <span className="po-detail-address-value">{poVerifyLocation.contact_number}</span>
                          </div>
                        )}
                        {poVerifyLocation.email && (
                          <div className="po-detail-address-row">
                            <span className="po-detail-address-label">Email</span>
                            <span className="po-detail-address-value">{poVerifyLocation.email}</span>
                          </div>
                        )}
                        {poVerifyLocation.manager_name && (
                          <div className="po-detail-address-row">
                            <span className="po-detail-address-label">Manager</span>
                            <span className="po-detail-address-value">{poVerifyLocation.manager_name}</span>
                          </div>
                        )}
                      </>
                    ) : (
                      <div className="po-detail-address-row">
                        <span className="po-detail-address-label">Branch / Location</span>
                        <span className="po-detail-address-value po-detail-address-value--name">—</span>
                      </div>
                    )}
                  </div>
                </div>
                {/* Meta info */}
                <div className="po-detail-info-grid">
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">PREPARED BY</span>
                    <span className="po-detail-info-value">
                      {poVerifyCreatedBy
                        ? `${poVerifyCreatedBy.first_name || ''} ${poVerifyCreatedBy.last_name || ''}`.trim() || poVerifyCreatedBy.username || poVerifyCreatedBy.email || '—'
                        : po.prepared_by || '—'}
                    </span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">DATE</span>
                    <span className="po-detail-info-value">{po.date || '—'}</span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">EXPECTED DELIVERY</span>
                    <span className="po-detail-info-value">{po.expected_delivery_date ? formatYmd(po.expected_delivery_date) : '—'}</span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">TOTAL AMOUNT</span>
                    <span className="po-detail-info-value" style={{ color: '#dc2626', fontWeight: 800 }}>₱{amount.toLocaleString('en-PH')}</span>
                  </div>
                  {(poVerifyApprovedBy || po.approved_by_name || po.status === 'authorized') && (
                    <div className="po-detail-info-item">
                      <span className="po-detail-info-label">APPROVED BY</span>
                      <span className="po-detail-info-value">
                        {poVerifyApprovedBy
                          ? `${poVerifyApprovedBy.first_name || ''} ${poVerifyApprovedBy.last_name || ''}`.trim() || poVerifyApprovedBy.username || poVerifyApprovedBy.email || '—'
                          : po.approved_by_name || '—'}
                      </span>
                    </div>
                  )}
                </div>

                {/* ── Line items ── */}
                <div className="po-detail-items-wrap">
                  <div className="po-detail-items-title-row">
                    <span className="po-detail-items-heading">LINE ITEMS</span>
                    <span className="po-detail-items-count">{itemCount}</span>
                  </div>
                  {poVerifyLoading && <p className="po-detail-no-items">Loading line items…</p>}
                  {poVerifyError && !poVerifyLoading && <p className="po-detail-no-items" style={{ color: '#dc2626' }}>{poVerifyError}</p>}
                  {!poVerifyLoading && poVerifyLines.length === 0 && !poVerifyError && (
                    <p className="po-detail-no-items">No line items available for this PO.</p>
                  )}
                  {!poVerifyLoading && poVerifyLines.length > 0 && (
                    <table className="po-detail-table">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>PRODUCT</th>
                          <th>QTY</th>
                          <th>UNIT PRICE</th>
                          <th>SUBTOTAL</th>
                        </tr>
                      </thead>
                      <tbody>
                        {poVerifyLines.map((row, idx) => (
                          <tr key={row.key}>
                            <td className="po-detail-table-idx">{idx + 1}</td>
                            <td>
                              <span style={{ display: 'block', fontWeight: 600 }}>{row.name}</span>
                              {(row.brand || row.category) && (
                                <span style={{ fontSize: 11, color: '#9ca3af' }}>
                                  {[row.brand, row.category].filter(Boolean).join(' · ')}
                                </span>
                              )}
                              {row.approved_by && (
                                <span style={{ display: 'block', fontSize: 11, color: '#15803d', marginTop: 2 }}>
                                  ✓ Approved by: {row.approved_by}
                                </span>
                              )}
                            </td>
                            <td>{row.qty}</td>
                            <td>₱{Number(row.unit || 0).toLocaleString('en-PH')}</td>
                            <td className="po-detail-table-sub">₱{Number(row.lineTotal || 0).toLocaleString('en-PH')}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>

                {/* ── Total ── */}
                <div className="po-detail-total">
                  <span className="po-detail-total-label">TOTAL ESTIMATED VALUE</span>
                  <span className="po-detail-total-value">₱{amount.toLocaleString('en-PH')}</span>
                </div>
              </div>

              {/* ── Actions ── */}
              {(() => {
                const poPayload = {
                  po_number: po.po_number,
                  status:    statusCfg.label,
                  supplier: {
                    name:           poVerifySupplier?.supplier_name || po.supplier,
                    address:        poVerifySupplier?.address,
                    origin:         poVerifySupplier?.origin,
                    region:         poVerifySupplier?.region,
                    contact_person: poVerifySupplier?.contact_person,
                    contact_number: poVerifySupplier?.contact_number,
                    email:          poVerifySupplier?.email,
                    tin:            poVerifySupplier?.tin,
                  },
                  ship_to: {
                    name:           poVerifyLocation?.location_name,
                    address:        poVerifyLocation
                      ? [poVerifyLocation.address, poVerifyLocation.city, poVerifyLocation.province, poVerifyLocation.region].filter(Boolean).join(', ') || null
                      : null,
                    contact_number: poVerifyLocation?.contact_number,
                    email:          poVerifyLocation?.email,
                    manager_name:   poVerifyLocation?.manager_name,
                  },
                  prepared_by:  po.prepared_by,
                  approved_by:  poVerifyApprovedBy
                    ? `${poVerifyApprovedBy.first_name || ''} ${poVerifyApprovedBy.last_name || ''}`.trim() || null
                    : (po.approved_by_name || null),
                  order_date:             po.date || '—',
                  expected_delivery_date: po.expected_delivery_date ? String(po.expected_delivery_date).slice(0, 10) : '—',
                  items: poVerifyLines.map((row) => ({
                    name:       row.name,
                    qty:        row.qty,
                    unit_price: row.unit,
                    subtotal:   row.lineTotal,
                  })),
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
          </div>
        );
      })()}
    </AdminLayout>
  );
};

export default ApprovalQueue;
