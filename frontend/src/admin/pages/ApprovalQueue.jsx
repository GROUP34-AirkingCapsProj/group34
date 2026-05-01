import React, { useEffect, useMemo, useState, useCallback } from 'react';
import { useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { useAuth } from '../context/AuthContext';
import { canActOnApprovalQueue, canApprovePendingRegistrations } from '../utils/roles';
import { purchaseOrdersAPI, statusAPI, productsAPI, pendingProductsAPI, consumableSupplyAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/approval_queue.css';
import '../styles/po_recommendation.css';
import { exportPOToExcel, exportPOToDocument } from '../utils/poExport';

const AQ_TABS = ['item-registration', 'purchase-orders', 'branch-transfers'];

const formatYmd = (value) => {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
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
  const canApproveRegistrations = useMemo(() => canApprovePendingRegistrations(user), [user]);
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


  // Fetch server POs when purchase-orders tab becomes active
  useEffect(() => {
    if (activeTab === 'purchase-orders') {
      fetchServerPOs();
    }
}, [activeTab, fetchServerPOs]);

  const [branchTransfers] = useState([]);

  const [pendingRegistrations, setPendingRegistrations] = useState([]);
  const [pendingRegLoading, setPendingRegLoading] = useState(false);
  const [packLabels, setPackLabels] = useState({});
  const [irApproveOpen, setIrApproveOpen] = useState(false);
  const [irSelected, setIrSelected] = useState(null);
  const [irApproveForm, setIrApproveForm] = useState({
    final_product_name: '',
  });
  const [irSubmitting, setIrSubmitting] = useState(false);

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

  useEffect(() => {
    consumableSupplyAPI.getCatalog().then((res) => {
      const units = res?.data?.packaging_units;
      const map = {};
      if (Array.isArray(units)) {
        units.forEach((u) => {
          if (u?.key) map[u.key] = u.label || u.key;
        });
      }
      setPackLabels(map);
    }).catch(() => setPackLabels({}));
  }, []);

  const fetchPendingRegistrations = useCallback(async () => {
    setPendingRegLoading(true);
    try {
      const res = await pendingProductsAPI.getAll({ per_page: 100 });
      const raw = res?.data;
      const list = Array.isArray(raw) ? raw : raw && Array.isArray(raw.data) ? raw.data : [];
      setPendingRegistrations(list);
    } catch (e) {
      toast.error(e.message || 'Could not load pending item registrations.');
      setPendingRegistrations([]);
    } finally {
      setPendingRegLoading(false);
    }
  }, []);

  useEffect(() => {
    if (activeTab === 'item-registration') {
      fetchPendingRegistrations();
    }
  }, [activeTab, fetchPendingRegistrations]);

  const packLabel = (key) => packLabels[key] || key || '—';

  const openIrApprove = (row) => {
    setIrSelected(row);
    setIrApproveForm({
      final_product_name: row.generated_name || '',
    });
    setIrApproveOpen(true);
  };

  const handleIrApproveSubmit = async (e) => {
    e.preventDefault();
    if (!irSelected?.pending_product_id) return;
    const name = irApproveForm.final_product_name.trim();
    if (!name) {
      toast.error('Product name is required.');
      return;
    }
    setIrSubmitting(true);
    try {
      await pendingProductsAPI.approve(irSelected.pending_product_id, {
        final_product_name: name,
      });
      toast.success('Approved — item is in Item Master. Add unit price and cost there if needed.');
      setIrApproveOpen(false);
      setIrSelected(null);
      fetchPendingRegistrations();
    } catch (err) {
      toast.error(err.message || 'Approval failed.');
    } finally {
      setIrSubmitting(false);
    }
  };

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

return (
    <AdminLayout>
      <div className="aq-content">
        {/* Page Header */}
        <div className="aq-page-header">
          <h1>Approvals</h1>
          {!canActOnQueue && !canApproveRegistrations && (
            <p className="aq-viewonly-banner">
              <strong>View only.</strong> Only <strong>Admin</strong> and <strong>Branch Manager</strong> can approve
              item registrations and authorize purchase orders / restock here. You can review and open PO line items
              with the eye icon.
            </p>
          )}
          {!canActOnQueue && canApproveRegistrations && (
            <p className="aq-viewonly-banner">
              <strong>Item registration:</strong> you can approve pending catalog submissions.{' '}
              <strong>Purchase orders</strong> and <strong>restock</strong> on this page are view-only unless you are
              Admin or Branch Manager.
            </p>
          )}
          <p>
            <strong>Item registration</strong> is approval-only: confirm the catalog name here;{' '}
            <strong>unit price and cost</strong> are set later in <strong>Item Master</strong>. When a draft has{' '}
            <strong>Item Master</strong> lines and a{' '}
            <strong>branch-linked location</strong>, <strong>Final Submission</strong> creates the purchase order in the{' '}
            <strong>database</strong> immediately (status Pending Approval) so it appears in <strong>Receive PO</strong>.{' '}
            {canActOnQueue ? (
              <>
                <strong>Approve</strong> sets it to <strong>Authorized</strong> on the server. Receiving stays blocked
                until authorized and on or after the expected delivery date when set.
              </>
            ) : (
              <>
                <strong>Admin</strong> or <strong>Branch Manager</strong> must <strong>Authorize</strong> on the server
                before receiving can proceed (expected delivery date rules still apply).
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
            className={`aq-tab-btn ${activeTab === 'item-registration' ? 'active' : ''}`}
            onClick={() => setActiveTab('item-registration')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="12" y1="18" x2="12" y2="12"></line>
              <line x1="9" y1="15" x2="15" y2="15"></line>
            </svg>
            Item registration
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
            Branch Transfers
          </button>
        </div>

        {/* Item registration (pending products → Item Master) */}
        {activeTab === 'item-registration' && (
          <div className="aq-list">
            {pendingRegLoading ? (
              <p className="aq-meta" style={{ padding: 24 }}>Loading pending registrations…</p>
            ) : pendingRegistrations.length === 0 ? (
              <div className="aq-empty-state">
                <div className="aq-empty-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                  </svg>
                </div>
                <p>No products waiting for registration approval.</p>
              </div>
            ) : (
              pendingRegistrations.map((row) => {
                const kind = row.registration_kind === 'appliance' ? 'Appliance' : 'Consumable';
                const snap = row.appliance_snapshot || {};
                const detail =
                  row.registration_kind === 'appliance'
                    ? [snap.capacity_rating, snap.variant].filter(Boolean).join(' · ') || '—'
                    : [row.supply_type, packLabel(row.packaging_unit)].filter(Boolean).join(' · ');
                return (
                  <div className="aq-card" key={row.pending_product_id}>
                    <div className="aq-card-top">
                      <div className="aq-card-title">
                        <h3>
                          <span
                            style={{
                              display: 'inline-block',
                              marginRight: 8,
                              fontSize: 11,
                              fontWeight: 600,
                              padding: '2px 8px',
                              borderRadius: 4,
                              verticalAlign: 'middle',
                              background: row.registration_kind === 'appliance' ? '#dbeafe' : '#fef3c7',
                              color: row.registration_kind === 'appliance' ? '#1e40af' : '#92400e',
                            }}
                          >
                            {kind}
                          </span>
                          {row.generated_name || '—'}
                        </h3>
                        <p>
                          Barcode <code>{row.barcode}</code>
                          {row.category?.category_name || row.brand?.brand_name
                            ? ` · ${[row.category?.category_name, row.brand?.brand_name].filter(Boolean).join(' · ')}`
                            : ''}
                        </p>
                      </div>
                    </div>
                    <div className="aq-card-bottom">
                      <div className="aq-card-info">
                        <span className="aq-meta">{detail}</span>
                        <span className="aq-meta" style={{ display: 'block', marginTop: 6 }}>
                          Submitted {formatDateTime(row.created_at)}
                          {row.creator?.username ? ` · ${row.creator.username}` : ''}
                          {Number(row.opening_quantity) > 0 ? (
                            <>
                              {' · '}
                              <strong>Opening stock:</strong>{' '}
                              {Number(row.opening_quantity).toLocaleString()}
                              {row.openingLocation?.location_name
                                ? ` @ ${row.openingLocation.location_name}`
                                : row.opening_location_id
                                  ? ` @ location #${row.opening_location_id}`
                                  : ''}
                            </>
                          ) : null}
                        </span>
                      </div>
                      <div className="aq-card-actions">
                        {canApproveRegistrations ? (
                          <button
                            type="button"
                            className="aq-btn-approve-restock"
                            onClick={() => openIrApprove(row)}
                          >
                            APPROVE
                          </button>
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

{/* Branch Transfers Tab */}
        {activeTab === 'branch-transfers' && (
          <div className="aq-list">
            {branchTransfers.length === 0 ? (
              <div className="aq-empty-state">
                <div className="aq-empty-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                  </svg>
                </div>
                <p>No pending transfers for approval</p>
              </div>
            ) : (
              branchTransfers.map(transfer => (
                <div className="aq-card" key={transfer.id}>
                  <p>{transfer.description}</p>
                </div>
              ))
            )}
          </div>
        )}
      </div>

      {irApproveOpen && irSelected && (
        <Modal
          title="Approve item registration"
          onClose={() => !irSubmitting && setIrApproveOpen(false)}
          maxWidth={480}
        >
          <form onSubmit={handleIrApproveSubmit}>
            <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 12 }}>
              Barcode <code>{irSelected.barcode}</code>
              {' · '}
              {irSelected.registration_kind === 'appliance' ? 'Appliance' : 'Consumable'}
            </p>
            <p style={{ fontSize: 13, color: '#1e40af', background: '#eff6ff', padding: 10, borderRadius: 8, marginBottom: 12 }}>
              Pricing is not entered here. After approval, open this product in <strong>Item Master</strong> to set unit price and cost.
            </p>
            <label style={{ display: 'block', marginBottom: 16, fontSize: 13, fontWeight: 600 }}>
              Catalog product name
              <input
                type="text"
                style={{ width: '100%', marginTop: 4, padding: '8px 10px', border: '1px solid #e5e7eb', borderRadius: 6 }}
                value={irApproveForm.final_product_name}
                onChange={(e) =>
                  setIrApproveForm((f) => ({ ...f, final_product_name: e.target.value }))
                }
                required
              />
            </label>
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <button type="button" className="aq-modal-done" disabled={irSubmitting} onClick={() => setIrApproveOpen(false)}>
                Cancel
              </button>
              <button type="submit" className="aq-btn-approve-restock" disabled={irSubmitting} style={{ border: 'none', cursor: 'pointer' }}>
                {irSubmitting ? 'Approving…' : 'Approve & add to Item Master'}
              </button>
            </div>
          </form>
        </Modal>
      )}

      {poVerifyModal && (() => {
        const po = poVerifyModal;
        const statusMap = {
          pending:    { label: 'PENDING APPROVAL', color: 'pending' },
          authorized: { label: 'APPROVED',         color: 'approved' },
          completed:  { label: 'APPROVED',         color: 'approved' },
          rejected:   { label: 'REJECTED',         color: 'rejected' },
          cancelled:  { label: 'CANCELLED',        color: 'cancelled' },
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
                    <span className="po-detail-info-value">{po.expected_delivery_date ? String(po.expected_delivery_date).slice(0, 10) : '—'}</span>
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
