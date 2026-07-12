import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import ArchiveButton from '../components/ArchiveButton';
import {
  suppliersAPI,
  purchaseOrdersAPI,
  purchaseRequestsAPI,
  locationsAPI,
  usersAPI,
  companySettingsAPI,
  statusAPI,
} from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import '../styles/archive_management.css';
import { exportPOToExcel, exportPOToDocument } from '../utils/poExport';
import PRProgressBar from '../components/PRProgressBar';
import DateInput from '../components/DateInput';
import { confirmAction, confirmWithReason } from '../utils/confirm';
import { toast } from '../utils/toast';
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtPeso = (n) =>
  `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const isoToMDY = (s) => {
  if (!s) return '—';
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return `${m}/${d}/${y}`;
};

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

// Returns the unit abbreviation to display in the printed PO document
const getUnitForExport = (lineItem) => {
  if (lineItem.unit_abbreviation) return lineItem.unit_abbreviation;
  if (lineItem.product_type === 'appliance') return 'unit';
  if (lineItem.unit_name) return lineItem.unit_name;
  return 'pc';
};

// Returns the full description for the printed PO document
const getDescriptionForExport = (lineItem) => {
  const name = lineItem.product || lineItem.product_name || '';
  if (lineItem.product_type === 'appliance') {
    return [name, lineItem.brand_name, lineItem.capacity_rating, lineItem.variant]
      .filter(Boolean).join(' ').toUpperCase();
  }
  if (lineItem.product_type === 'installation_material') {
    let desc = name.toUpperCase();
    if (lineItem.pieces_per_package) {
      desc += ` (${lineItem.pieces_per_package}pcs/${lineItem.package_unit || 'Box'})`;
    }
    return desc;
  }
  return name.toUpperCase();
};

// Group PO detail rows by destination warehouse so each location's items show
// together under one header. Returns [{ locationName, rows, subtotal }].
const groupPoDetailsByLocation = (details) => {
  const map = new Map();
  (details || []).forEach((d) => {
    const key  = String(d.location_id ?? d.location?.location_id ?? '__none__');
    const name = d.location_name || d.location?.location_name || 'Unassigned warehouse';
    if (!map.has(key)) map.set(key, { locationName: name, rows: [], subtotal: 0 });
    const g = map.get(key);
    g.rows.push(d);
    g.subtotal += Number(d.subtotal) || 0;
  });
  return [...map.values()];
};

// V5 product detail label for PO line items (snake_case backend fields)
const poLineLabel = (item) => {
  if (item.product_type === 'installation_material') {
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
  const { user: authUser, can } = useAuth();
  const roleKey = getRoleKey(authUser);
  const isAdmin = roleKey === ROLES.ADMIN;
  // Purchase order creation: admin and inventory analyst.
  const canCreatePO = isAdmin || (typeof can === 'function' && can('purchase_order_creation', 'create'));
  // Purchase order approval: admin and branch manager (update permission). Mirrors the
  // gate the retired Approval Queue used for approve/reject.
  const canApprovePO = isAdmin || (typeof can === 'function' && can('purchase_order_creation', 'update'));
  // Purchase order verification (auditor maker-checker gate).
  const canVerifyPO = typeof can === 'function' && can('purchase_order_verification', 'verify');

  // Tabs
  const [activeTab, setActiveTab] = useState('create');

  // Suppliers
  const [suppliers,          setSuppliers]          = useState([]);
  const [loadingSuppliers,   setLoadingSuppliers]   = useState(true);
  const [selectedSupplierId, setSelectedSupplierId] = useState('');

  // New PO form fields
  const [paymentTerms,    setPaymentTerms]    = useState('Net 30');
  const [notes,           setNotes]           = useState('');
  const [referencesNote,  setReferencesNote]  = useState('');
  const [shipToName,      setShipToName]      = useState('');
  const [shipToAddress,   setShipToAddress]   = useState('');
  const [showPreview,     setShowPreview]     = useState(false);

  // Reference data for new fields
  const [allUsers,         setAllUsers]         = useState([]);
  const [locations,        setLocations]        = useState([]);
  const [companySettings,  setCompanySettings]  = useState({});

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
  const [loadingConvertForm, setLoadingConvertForm] = useState(false);
  // New (supplier-scoped, per-warehouse) PR shape: supplier is locked from the PR
  // and each line carries its own destination warehouse (location_id/warehouse_name).
  const [isNewShapePR, setIsNewShapePR] = useState(false);
  const [lockedSupplierId, setLockedSupplierId] = useState(null);

  // Submit
  const [submitting,     setSubmitting]     = useState(false);

  // PO Status tab
  const [poStatusList,    setPoStatusList]    = useState([]);
  const [loadingPOStatus, setLoadingPOStatus] = useState(false);
  const [poStatusLoaded,  setPoStatusLoaded]  = useState(false);
  const [poStatusError,   setPoStatusError]   = useState('');
  const [selectedPO,      setSelectedPO]      = useState(null);
  // Per-PO approve/reject in-flight flag (holds the po_id being acted on)
  const [poActionLoading, setPoActionLoading] = useState(null);

  // Deep-link focus state
  const focusPoId = useFocusTarget('po');
  const focusHandledRef = useRef(false);
  const [poPulse, firePoPulse] = useFocusPulse();

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

  // ── Load users, locations, and company settings once ──────────────────────
  useEffect(() => {
    usersAPI.getAll({ per_page: 200 })
      .then(res => setAllUsers(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});

    locationsAPI.getAll({ per_page: 100 })
      .then(res => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => {});

    companySettingsAPI.get()
      .then(res => setCompanySettings(res || {}))
      .catch(() => {});
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
      .finally(() => { if (mounted) { setLoadingPOStatus(false); setPoStatusLoaded(true); } });
    return () => { mounted = false; };
  }, [activeTab]);

  // Deep-link: a ?focus=po:<id> arrival switches to the status tab…
  useEffect(() => {
    if (focusPoId && !focusHandledRef.current) setActiveTab('status');
  }, [focusPoId]);

  // …then, once the status list has loaded, opens that PO (or toasts if gone).
  useEffect(() => {
    if (!focusPoId || focusHandledRef.current) return;
    if (activeTab !== 'status' || !poStatusLoaded) return;
    focusHandledRef.current = true;
    const po = findById(poStatusList, ['po_id', 'id'], focusPoId);
    if (po) { setSelectedPO(po); firePoPulse(); }
    else toast.error('That purchase order is no longer available.');
  }, [focusPoId, activeTab, poStatusLoaded, poStatusList, firePoPulse]);

  // ── PR selection ───────────────────────────────────────────────────────────
  // Pulls the convert-prefill endpoint so we get per-line location_id/warehouse_name
  // and the PR's locked-in supplier (new warehouse-only PR shape). Legacy PRs (no
  // line-level warehouses) fall back to the old flat supplier-picker behavior.
  const handleSelectPR = useCallback(async (pr) => {
    setSelectedPR(pr);
    setReorderNotes({});
    setLineItems([]);
    setIsNewShapePR(false);
    setLockedSupplierId(null);
    setLoadingConvertForm(true);
    try {
      const res = await purchaseRequestsAPI.getConvertForm(pr.pr_id);
      const data = res?.data || {};
      const prefilled = Array.isArray(data.prefilled_lines) ? data.prefilled_lines : [];
      const newShape = !!data.pr_supplier_id && prefilled.some(l => l.location_id != null);
      setIsNewShapePR(newShape);
      if (newShape) {
        setLockedSupplierId(String(data.pr_supplier_id));
        setSelectedSupplierId(String(data.pr_supplier_id));
      }
      setLineItems(prefilled.map(d => {
        const prQty     = Number(d.quantity_ordered || 0);
        const unitPrice = Number(d.unit_price || 0);
        return {
          id:                `pr-line-${d.product_id}-${d.location_id ?? 'na'}`,
          product_id:        d.product_id,
          product:           d.product_name || `Product ${d.product_id}`,
          product_code:      d.product_code || '',
          model_code:        d.model_code || '',
          product_type:      d.product_type || '',
          brand_name:        d.brand_name ?? null,
          capacity_rating:   d.capacity_rating ?? null,
          variant:           d.variant ?? null,
          unit_name:         d.unit_name ?? null,
          pieces_per_package: d.pieces_per_package ?? null,
          package_unit:      d.package_unit ?? null,
          location_id:       d.location_id ?? null,
          warehouse_name:    d.warehouse_name ?? null,
          pr_qty:            prQty,
          quantity_ordered:  prQty,
          unit_price:        unitPrice,
          subtotal:          prQty * unitPrice,
        };
      }));
    } catch (err) {
      toast.error("Couldn't load Purchase Request", err?.message || 'Failed to load convert form.');
      // Fall back to whatever details came with the list row so the flow isn't dead-ended.
      const details = Array.isArray(pr.details) ? pr.details : [];
      setLineItems(details.map(d => {
        const prQty     = Number(d.quantity || 0);
        const unitPrice = Number(d.estimated_unit_price ?? d.unit_price ?? d.estimated_price ?? 0);
        return {
          id:                `pr-line-${d.pr_detail_id ?? d.id ?? Math.random()}`,
          product_id:        d.product_id,
          product:           d.product_name || d.product?.product_name || `Product ${d.product_id}`,
          product_code:      d.product_code || d.product?.product_code || '',
          model_code:        d.product?.applianceDetail?.model_code || d.model_code || '',
          product_type:      d.product_type || d.product?.product_type || '',
          brand_name:        d.brand_name ?? null,
          capacity_rating:   d.capacity_rating ?? null,
          variant:           d.variant ?? null,
          unit_name:         d.unit_name ?? null,
          pieces_per_package: d.pieces_per_package ?? null,
          package_unit:      d.package_unit ?? null,
          location_id:       d.location_id ?? d.location?.location_id ?? null,
          warehouse_name:    d.location?.location_name ?? null,
          pr_qty:            prQty,
          quantity_ordered:  prQty,
          unit_price:        unitPrice,
          subtotal:          prQty * unitPrice,
        };
      }));
    } finally {
      setLoadingConvertForm(false);
    }
    // Fetch open demand for each product (excluding this PR) to show informational notes
    const details = Array.isArray(pr.details) ? pr.details : [];
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
    setReorderNotes({});
    setPaymentTerms('Net 30');
    setNotes('');
    setReferencesNote('');
    setShipToName('');
    setShipToAddress('');
    setShowPreview(false);
    setIsNewShapePR(false);
    setLockedSupplierId(null);
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

  // ── Submit ─────────────────────────────────────────────────────────────────
  const handleSubmitPO = useCallback(async () => {
    if (!selectedPR)         { toast.error("Couldn't create PO", 'Please select an approved Purchase Request first.'); return; }
    if (!isNewShapePR && !selectedSupplierId) { toast.error("Couldn't create PO", 'Please select a supplier.'); return; }
    if (lineItems.length === 0) { toast.error("Couldn't create PO", 'No line items to submit.'); return; }
    if (isNewShapePR && lineItems.some(li => li.location_id == null)) {
      toast.error("Couldn't create PO", 'One or more lines is missing its destination warehouse.');
      return;
    }
    if (!(await confirmAction({ title: 'Submit this purchase order?', confirmText: 'Submit PO', tone: 'primary' }))) return;
    setSubmitting(true);
    try {
      const payload = {
        ...(isNewShapePR ? {} : { supplier_id: Number(selectedSupplierId) }),
        expected_delivery_date: expectedDeliveryDate || null,
        payment_terms:          paymentTerms,
        notes:                  notes.trim()          || null,
        references_note:        referencesNote.trim() || null,
        ship_to_name:           shipToName.trim()     || null,
        ship_to_address:        shipToAddress.trim()  || null,
        details: lineItems.map(li => ({
          product_id:       li.product_id,
          quantity_ordered: li.quantity_ordered,
          unit_price:       li.unit_price,
          subtotal:         li.quantity_ordered * li.unit_price,
          ...(isNewShapePR ? { location_id: li.location_id } : {}),
        })),
      };
      const res   = await purchaseRequestsAPI.convertToPO(selectedPR.pr_id, payload);
      const data  = res?.data;
      const poNum = data?.po_number || data?.po?.po_number || 'New PO';
      if (isNewShapePR) {
        const whCount = new Set(lineItems.map(li => li.location_id)).size;
        toast.success(
          whCount > 1 ? 'Purchase Orders created' : 'Purchase Order created',
          whCount > 1
            ? `${poNum} + ${whCount - 1} more — one PO per warehouse created from ${selectedPR.pr_number}, waiting for admin approval`
            : `${poNum} from ${selectedPR.pr_number} — waiting for admin approval`
        );
      } else {
        toast.success('Purchase Order created', `${poNum} from ${selectedPR.pr_number} — waiting for admin approval`);
      }
      setApprovedPRs(prev => prev.filter(p => p.pr_id !== selectedPR.pr_id));
      setSelectedPR(null);
      setLineItems([]);
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to create purchase order.';
      toast.error("Couldn't create PO", msg);
    } finally {
      setSubmitting(false);
    }
  }, [selectedPR, selectedSupplierId, isNewShapePR, expectedDeliveryDate, lineItems, paymentTerms, notes, referencesNote, shipToName, shipToAddress]);

  // ── PO Status list refresh (single source of truth) ──────────────────────────
  const refreshPOStatusList = useCallback(async () => {
    const res = await purchaseOrdersAPI.getAll({ per_page: 100 });
    setPoStatusList(Array.isArray(res?.data) ? res.data : []);
  }, []);

  // ── Archive PO ───────────────────────────────────────────────────────────────
  const handleArchivePO = useCallback(async (poId) => {
    await purchaseOrdersAPI.delete(poId);
    await refreshPOStatusList();
  }, [refreshPOStatusList]);

  // Shared approve/reject flow: resolve the target status_id from the lookup,
  // update the PO, refresh the list, and surface the right toasts. Both the
  // approve and reject handlers delegate here with their own copy.
  const applyPOStatus = useCallback(async (po, { match, missingLabel, successTitle, successText, errorTitle, genericError }) => {
    setPoActionLoading(po.po_id);
    try {
      const stRes = await statusAPI.getAll({ category: 'purchase_order' });
      const stRows = Array.isArray(stRes?.data) ? stRes.data : [];
      const statusId = stRows.find((s) => match(String(s.status_name || '').toLowerCase()))?.status_id;
      if (!statusId) {
        toast.error(errorTitle, `No "${missingLabel}" status for purchase orders. Run backend migrations, then try again.`);
        return;
      }
      await purchaseOrdersAPI.update(String(po.po_id), {
        status_id: statusId,
        ...(authUser?.user_id ? { approved_by: authUser.user_id } : {}),
      });
      await refreshPOStatusList();
      setSelectedPO(null);
      toast.success(successTitle, successText(po));
    } catch (err) {
      if (err?.status === 403) {
        toast.error(errorTitle, 'This account cannot update purchase orders.');
        return;
      }
      const msg = err?.errors ? Object.values(err.errors).flat().join(' ') : err?.message || genericError;
      toast.error(errorTitle, msg);
    } finally {
      setPoActionLoading(null);
    }
  }, [authUser, refreshPOStatusList]);

  const handleApprovePO = useCallback((po) => applyPOStatus(po, {
    match: (n) => n.includes('authori'),
    missingLabel: 'Authorized',
    successTitle: 'PO authorized',
    successText: (p) => `${p.po_number} is now Approved.`,
    errorTitle: "Couldn't authorize PO",
    genericError: 'Could not authorize this PO.',
  }), [applyPOStatus]);

  const handleRejectPO = useCallback(async (po) => {
    if (!(await confirmAction({
      title: 'Reject this purchase order?',
      text: `${po.po_number} will be marked Rejected and cannot proceed to receiving.`,
      confirmText: 'Reject',
      tone: 'danger',
    }))) return;
    await applyPOStatus(po, {
      match: (n) => n.includes('reject'),
      missingLabel: 'Rejected',
      successTitle: 'PO rejected',
      successText: (p) => `${p.po_number} has been rejected.`,
      errorTitle: "Couldn't reject PO",
      genericError: 'Could not reject this PO.',
    });
  }, [applyPOStatus]);

  // ── PO Verification (auditor maker-checker gate) ──────────────────────────
  const handleVerifyPO = useCallback(async (po) => {
    setPoActionLoading(po.po_id);
    try {
      await purchaseOrdersAPI.verify(String(po.po_id));
      await refreshPOStatusList();
      toast.success('Purchase order verified', `${po.po_number} is ready for approval.`);
    } catch (err) {
      toast.error("Couldn't verify PO", err?.message || 'Verification failed.');
    } finally { setPoActionLoading(null); }
  }, [refreshPOStatusList]);

  const handleRejectPOVerification = useCallback(async (po) => {
    const { confirmed, reason } = await confirmWithReason({
      title: 'Reject this purchase order?',
      confirmText: 'Reject',
      tone: 'danger',
      reasonRequired: true,
      reasonLabel: 'Reason for rejection',
      reasonPlaceholder: 'Explain why this purchase order is being sent back…',
    });
    if (!confirmed) return;
    setPoActionLoading(po.po_id);
    try {
      await purchaseOrdersAPI.reject(String(po.po_id), reason);
      await refreshPOStatusList();
      toast.success('Purchase order rejected', `${po.po_number} was sent back.`);
    } catch (err) {
      toast.error("Couldn't reject PO", err?.message || 'Rejection failed.');
    } finally { setPoActionLoading(null); }
  }, [refreshPOStatusList]);

  // ── PO Status renderer ─────────────────────────────────────────────────────
  const renderPoStatusView = () => {
    if (loadingPOStatus) return <Loader label="Loading purchase orders" />;
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
                <div style={{ background: '#eff6ff', borderBottom: '1px solid #bfdbfe', padding: '6px 16px', fontSize: 12, color: '#1d4ed8', borderRadius: '10px 10px 0 0', display: 'flex', alignItems: 'center', gap: 5 }}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13" style={{ flexShrink: 0 }}><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  From PR:{' '}
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
                      <span className="po-status-meta-value">{isoToMDY(po.order_date)}</span>
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
                  {statusCfg.color === 'pending' && canApprovePO && (
                    <div className="po-status-approve-actions">
                      <button
                        type="button"
                        className="aq-btn-reject"
                        disabled={poActionLoading === po.po_id}
                        onClick={() => handleRejectPO(po)}
                      >
                        REJECT
                      </button>
                      <button
                        type="button"
                        className="aq-btn-approve"
                        disabled={poActionLoading === po.po_id}
                        onClick={() => handleApprovePO(po)}
                      >
                        {poActionLoading === po.po_id ? 'SAVING…' : 'APPROVE'}
                      </button>
                    </div>
                  )}
                  {canVerifyPO && statusCfg.color === 'pending' && po.verification_status !== 'verified' && (
                    <div className="po-status-approve-actions">
                      <button
                        type="button"
                        className="aq-btn-reject"
                        disabled={poActionLoading === po.po_id}
                        onClick={() => handleRejectPOVerification(po)}
                      >
                        REJECT
                      </button>
                      <button
                        type="button"
                        className="aq-btn-approve"
                        disabled={poActionLoading === po.po_id}
                        onClick={() => handleVerifyPO(po)}
                      >
                        {poActionLoading === po.po_id ? 'SAVING…' : 'VERIFY'}
                      </button>
                    </div>
                  )}
                  <ArchiveButton
                    onArchive={() => handleArchivePO(po.po_id)}
                    disabled={!['completed', 'rejected', 'cancelled'].includes(statusCfg.color)}
                  />
                </div>
              </div>
              {po.verification_status === 'pending' && (
                <div className="po-status-note" style={{ background: '#fffbeb', color: '#92400e' }}>
                  <span>Awaiting auditor verification</span>
                </div>
              )}
              {po.verification_status === 'verified' && (
                <div className="po-status-note po-status-note--approved">
                  <span>Verified by {po.verifiedBy ? `${po.verifiedBy.first_name} ${po.verifiedBy.last_name}` : 'auditor'}{po.verified_at ? ` on ${String(po.verified_at).slice(0, 10)}` : ''}</span>
                </div>
              )}
              {po.verification_status === 'rejected' && (
                <div className="po-status-note po-status-note--rejected">
                  <span>Rejected by auditor{po.verification_note ? `: ${po.verification_note}` : ''}</span>
                </div>
              )}
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

          {loadingPRs && <Loader label="Loading approved purchase requests" />}
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
              <button type="button" className="po-btn po-btn-add-product" onClick={() => navigate('/admin/purchase-requests')} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                Go to Purchase Requests
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
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
                          {pr.supplier?.supplier_name && (
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="12" height="12"><path d="M3 3h18v4H3z"/><path d="M5 7v13h14V7"/><path d="M9 21V11h6v10"/></svg>
                              {pr.supplier.supplier_name}
                            </span>
                          )}
                          {pr.location?.location_name && (
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="12" height="12"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                              {pr.location.location_name}
                            </span>
                          )}
                          <span>{itemCount} {itemCount === 1 ? 'item' : 'items'}</span>
                          {neededBy && <span>Needed by: {neededBy}</span>}
                          <span>Requested by: {reqBy}</span>
                          {estTotal > 0 && <span>Est: {fmtPeso(estTotal)}</span>}
                        </div>
                      </div>
                      <button
                        type="button"
                        className="po-btn po-btn-final-submission"
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 5, whiteSpace: 'nowrap', fontSize: 13, padding: '8px 16px' }}
                        onClick={() => handleSelectPR(pr)}
                      >
                        SELECT
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
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
    if (loadingConvertForm) return <Loader label="Loading purchase request" />;

    // Distinct destination warehouse(s) for this PO — a single PO now carries
    // per-line warehouses, so list them all (one PO per supplier).
    const deliverWarehouseNames = isNewShapePR
      ? [...new Set(lineItems.map(li => li.warehouse_name).filter(Boolean))]
      : [selectedPR.location?.location_name].filter(Boolean);
    const locationName = deliverWarehouseNames.length ? deliverWarehouseNames.join(', ') : '—';
    const lockedSupplierName = isNewShapePR
      ? (suppliers.find(s => String(s.supplier_id ?? s.id) === String(lockedSupplierId))?.supplier_name
         || suppliers.find(s => String(s.supplier_id ?? s.id) === String(lockedSupplierId))?.name
         || '—')
      : null;

    // Group line items by warehouse for the new (supplier-scoped, per-warehouse) PR shape.
    const groupedByWarehouse = isNewShapePR
      ? lineItems.reduce((acc, li) => {
          const key = li.location_id ?? 'na';
          if (!acc[key]) acc[key] = { warehouse_name: li.warehouse_name || '—', items: [] };
          acc[key].items.push(li);
          return acc;
        }, {})
      : null;

    return (
      <div>
        {/* PR info banner */}
        <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, padding: '10px 16px', marginBottom: 16, fontSize: 13, color: '#1e40af', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14" style={{ flexShrink: 0 }}><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Creating PO from Purchase Request: <strong style={{ marginLeft: 3 }}>{selectedPR.pr_number}</strong>
          </span>
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
                  {isNewShapePR ? (
                    <div className="po-reference-badge" title="Supplier is fixed by the Purchase Request and cannot be changed here.">
                      {lockedSupplierName}
                    </div>
                  ) : (
                    <select className="po-select" value={selectedSupplierId} onChange={e => setSelectedSupplierId(e.target.value)} disabled={loadingSuppliers}>
                      {loadingSuppliers && <option value="">Loading suppliers…</option>}
                      {!loadingSuppliers && suppliers.length === 0 && <option value="">No suppliers found</option>}
                      {suppliers.map(s => (
                        <option key={s.supplier_id ?? s.id} value={String(s.supplier_id ?? s.id)}>
                          {s.supplier_name ?? s.name}
                        </option>
                      ))}
                    </select>
                  )}
                </div>
                <div className="po-field">
                  <label className="po-field-label">EXPECTED DELIVERY DATE</label>
                  <DateInput className="po-input" value={expectedDeliveryDate} onChange={e => setExpectedDeliveryDate(e.target.value)} />
                </div>
                <div className="po-field">
                  <label className="po-field-label">PAYMENT TERMS</label>
                  <select className="po-select" value={paymentTerms} onChange={e => setPaymentTerms(e.target.value)}>
                    <option value="Net 30">Net 30</option>
                    <option value="Net 15">Net 15</option>
                    <option value="Net 60">Net 60</option>
                    <option value="Net 90">Net 90</option>
                    <option value="COD">COD</option>
                    <option value="Upon Delivery">Upon Delivery</option>
                  </select>
                </div>
              </div>

              {/* Ship To overrides */}
              <div style={{ marginTop: 16 }}>
                <div style={{ fontSize: 11, fontWeight: 700, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 8 }}>
                  SHIP TO / DELIVERY ADDRESS
                  <span style={{ fontWeight: 400, marginLeft: 6 }}>(shown on printed PO — leave blank to use location defaults)</span>
                </div>
                <div className="po-details-grid">
                  <div className="po-field">
                    <label className="po-field-label">SHIP TO — COMPANY / NAME</label>
                    <input
                      type="text"
                      className="po-input"
                      placeholder="e.g. Airking Air Conditioning CDO Branch"
                      value={shipToName}
                      onChange={e => setShipToName(e.target.value)}
                    />
                  </div>
                  <div className="po-field">
                    <label className="po-field-label">SHIP TO — ADDRESS</label>
                    <textarea
                      className="po-textarea"
                      rows={2}
                      placeholder="e.g. Zone 5, Bulua, Cagayan de Oro"
                      value={shipToAddress}
                      onChange={e => setShipToAddress(e.target.value)}
                    />
                  </div>
                </div>
                {locations.length > 0 && (
                  <div className="budget-quick-period-btns" style={{ marginTop: 6 }}>
                    <span style={{ fontSize: 12, color: '#64748b', marginRight: 6 }}>Quick fill:</span>
                    {locations.map(loc => (
                      <button
                        key={loc.location_id ?? loc.id}
                        type="button"
                        className="budget-quick-btn"
                        onClick={() => {
                          setShipToName(loc.location_name);
                          setShipToAddress([loc.address, loc.city, loc.province, loc.region].filter(Boolean).join(', '));
                        }}
                      >
                        {loc.location_name}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Notes and References */}
              <div className="po-details-grid" style={{ marginTop: 16 }}>
                <div className="po-field" style={{ gridColumn: 'span 2' }}>
                  <label className="po-field-label">NOTES</label>
                  <textarea
                    className="po-textarea"
                    rows={2}
                    placeholder="Any special instructions or notes…"
                    value={notes}
                    onChange={e => setNotes(e.target.value)}
                  />
                </div>
                <div className="po-field" style={{ gridColumn: 'span 2' }}>
                  <label className="po-field-label">REFERENCES</label>
                  <textarea
                    className="po-textarea"
                    rows={2}
                    placeholder="Reference documents or quotation numbers…"
                    value={referencesNote}
                    onChange={e => setReferencesNote(e.target.value)}
                  />
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
                      {(isNewShapePR ? Object.values(groupedByWarehouse) : [{ warehouse_name: null, items: lineItems }])
                        .flatMap((group, gi) => {
                          const rows = [];
                          if (isNewShapePR) {
                            rows.push(
                              <tr key={`wh-${gi}`}>
                                <td colSpan={8} style={{ background: '#f1f5f9', fontWeight: 700, fontSize: 12, color: '#334155', padding: '6px 10px' }}>
                                  Requested for: {group.warehouse_name}
                                </td>
                              </tr>
                            );
                          }
                          group.items.forEach(li => {
                            const isPartial   = li.quantity_ordered < li.pr_qty;
                            const remaining   = li.pr_qty - li.quantity_ordered;
                            const openDemand  = reorderNotes[li.product_id] || 0;
                            rows.push(
                              <React.Fragment key={li.id}>
                                <tr className={isPartial ? 'pr-item-remaining' : ''}>
                                  <td>
                                    <strong>{li.product}</strong>
                                    {li.model_code && <div style={{ fontSize: 11, color: '#94a3b8' }}>{li.model_code}</div>}
                                  </td>
                                  <td>
                                    <span className={`po-type-badge ${li.product_type === 'installation_material' ? 'po-type-part' : 'po-type-appliance'}`}>
                                      {li.product_type === 'installation_material' ? 'MATERIAL' : 'APPLIANCE'}
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
                                      ? <span style={{ color: '#d97706', display: 'inline-flex', alignItems: 'center', gap: 3 }}><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="12" height="12"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>Partial — {remaining} remaining</span>
                                      : <span style={{ color: '#16a34a', display: 'inline-flex', alignItems: 'center', gap: 3 }}><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2.5" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>Full</span>
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
                                        {openDemand} more unit(s) of <strong style={{ marginLeft: 3 }}>{li.product}</strong> on open demand from other PRs.
                                      </div>
                                    </td>
                                  </tr>
                                )}
                              </React.Fragment>
                            );
                          });
                          return rows;
                        })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            {/* Document Preview */}
            <div className="po-preview-panel">
              <button type="button" className="po-preview-toggle" onClick={() => setShowPreview(p => !p)}>
                {showPreview ? '▲ Hide Document Preview' : '▼ Preview Document'}
              </button>

              {showPreview && (() => {
                const previewSupplier = suppliers.find(s => String(s.supplier_id ?? s.id) === String(selectedSupplierId));
                const preparedByName  = authUser
                  ? `${authUser.first_name || ''} ${authUser.last_name || ''}`.trim() || authUser.username || ''
                  : '';
                // This PO does not exist yet at preview time, so there is no auditor
                // verification to show — it appears on the printed document once the
                // created PO has been verified (see the PO detail modal export).
                const verifiedByName  = '';
                // Approved By = whoever approved the PR (not the PO approval-queue step)
                const approvedByName  = (() => {
                  const apr = selectedPR?.approver;
                  if (apr && typeof apr === 'object') {
                    return `${apr.first_name || ''} ${apr.last_name || ''}`.trim() || apr.username || '';
                  }
                  const apId = selectedPR?.approved_by;
                  if (apId) {
                    const u = allUsers.find(u => String(u.user_id) === String(apId));
                    return u ? `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.username || '' : '';
                  }
                  return '';
                })();
                const todayStr = new Date().toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });

                return (
                  <div className="po-doc-preview">
                    {/* Company Header */}
                    <div className="po-doc-company-header">
                      <h2>{companySettings.company_name || 'Airking Air Conditioning'}</h2>
                      {companySettings.company_address && <p>{companySettings.company_address}</p>}
                      {companySettings.company_email   && <p>{companySettings.company_email}</p>}
                    </div>

                    {/* Supplier + PO reference */}
                    <div className="po-doc-header-grid">
                      <div className="po-doc-supplier">
                        <strong>{(previewSupplier?.supplier_name || '[SUPPLIER NAME]').toUpperCase()}</strong>
                        {previewSupplier?.address && <span>{previewSupplier.address}</span>}
                      </div>
                      <div className="po-doc-reference">
                        <h3>Purchase Order</h3>
                        <table className="po-doc-ref-table">
                          <tbody>
                            <tr><td>Date:</td><td>{todayStr}</td></tr>
                            <tr><td>Reference #:</td><td>[Auto-generated]</td></tr>
                            <tr><td>Payment Terms:</td><td>{paymentTerms}</td></tr>
                          </tbody>
                        </table>
                      </div>
                    </div>

                    {/* Deliver To (Warehouse) — where the supplier delivers each item */}
                    <div className="po-doc-ship-to" style={{ marginBottom: 16, fontSize: 13, lineHeight: 1.6, border: '1.5px solid #000', padding: '8px 12px', background: '#f4f4f4' }}>
                      <strong style={{ display: 'block', marginBottom: 2, textTransform: 'uppercase' }}>
                        Deliver To (Warehouse): {deliverWarehouseNames.length ? deliverWarehouseNames.join(', ') : (shipToName || '—')}
                      </strong>
                      {shipToAddress && <div>{shipToAddress}</div>}
                    </div>

                    {/* Line Items — grouped by destination warehouse (mirrors the export) */}
                    <div className="po-doc-details-label">Details</div>
                    <table className="po-doc-table">
                      <thead>
                        <tr>
                          <th>Quantity</th>
                          <th>Unit</th>
                          <th>Model Code</th>
                          <th>Description</th>
                          <th>Unit Price</th>
                          <th>Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(isNewShapePR ? Object.values(groupedByWarehouse) : [{ warehouse_name: locationName, items: lineItems }]).map((grp, gi) => (
                          <React.Fragment key={gi}>
                            <tr>
                              <td colSpan={6} style={{ background: '#f0f0f0', fontWeight: 700 }}>Deliver To: {grp.warehouse_name || '—'}</td>
                            </tr>
                            {grp.items.map((li, idx) => (
                              <tr key={`${gi}-${idx}`}>
                                <td>{li.quantity_ordered}</td>
                                <td>{getUnitForExport(li)}</td>
                                <td>{li.model_code || '—'}</td>
                                <td>{getDescriptionForExport(li)}</td>
                                <td>{Number(li.unit_price) === 0 ? 'Free' : `₱${Number(li.unit_price).toLocaleString('en-PH')}`}</td>
                                <td>{Number(li.unit_price) === 0 ? '0.00' : `₱${Number(li.subtotal).toLocaleString('en-PH')}`}</td>
                              </tr>
                            ))}
                          </React.Fragment>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr>
                          <td colSpan={5} style={{ textAlign: 'right' }}>Total Amount {'→'}</td>
                          <td><strong>₱{poTotal.toLocaleString('en-PH', { minimumFractionDigits: 2 })}</strong></td>
                        </tr>
                      </tfoot>
                    </table>

                    {/* Notes / References */}
                    <div className="po-doc-notes">
                      <div><strong>Notes:</strong> {notes || ''}</div>
                      <div><strong>References:</strong> {referencesNote || ''}</div>
                    </div>

                    {/* Signatures */}
                    <div className="po-doc-signatures">
                      <div className="po-doc-sig-block">
                        <div className="po-doc-sig-label">Prepared By:</div>
                        <div className="po-doc-sig-name">{preparedByName}</div>
                      </div>
                      <div className="po-doc-sig-block">
                        <div className="po-doc-sig-label">Verified By:</div>
                        <div className="po-doc-sig-name">{verifiedByName}</div>
                      </div>
                      <div className="po-doc-sig-block">
                        <div className="po-doc-sig-label">Approved By:</div>
                        <div className="po-doc-sig-name">{approvedByName}</div>
                      </div>
                    </div>
                  </div>
                );
              })()}
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
                <div style={{ background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, padding: '8px 10px', fontSize: 12, color: '#92400e', marginTop: 8, display: 'flex', alignItems: 'flex-start', gap: 6 }}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="14" height="14" style={{ flexShrink: 0, marginTop: 1 }}><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <span>This PO is {fmtPeso(prEstimatedTotal - poTotal)} less than the PR. PR will be Partially Converted.</span>
                </div>
              )}
              <div className="po-summary-total" style={{ marginTop: 12 }}>
                <span>FINAL PO TOTAL</span>
                <span className="po-summary-total-value">{fmtPeso(poTotal)}</span>
              </div>
            </div>

            {canCreatePO && (
              <button
                type="button"
                className="po-btn po-btn-final-submission"
                style={{ width: '100%', marginTop: 12 }}
                onClick={handleSubmitPO}
                disabled={submitting}
              >
                {submitting ? 'SUBMITTING…' : 'SUBMIT PURCHASE ORDER'}
              </button>
            )}

          </div>
        </div>
      </div>
    );
  };

  // ── Main render ────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page po-draft-creator-page">
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
          </div>
        </div>

        <div className="po-tab-nav">
          <button type="button" className={`po-tab-btn${activeTab === 'create' ? ' po-tab-btn--active' : ''}`} onClick={() => setActiveTab('create')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
              <path d="M8 13h8M8 17h5"/>
            </svg>
            Convert PR to PO
          </button>
          <button type="button" className={`po-tab-btn${activeTab === 'status' ? ' po-tab-btn--active' : ''}`} onClick={() => setActiveTab('status')}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
              <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
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
            <div className={`po-detail-modal${poPulse ? ' ak-focus-pulse' : ''}`}>
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
                    {d.supplier?.supplier_name || '—'} &nbsp;·&nbsp; {isoToMDY(d.order_date)}
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
                    <div className="po-detail-address-block-title">Deliver To (Warehouse)</div>
                    <div className="po-detail-address-row"><span className="po-detail-address-label">Warehouse</span><span className="po-detail-address-value po-detail-address-value--name">{[...new Set((details || []).map(x => x.location_name || x.location?.location_name).filter(Boolean))].join(', ') || d.location?.location_name || '—'}</span></div>
                    {(d.location?.address || d.location?.city || d.location?.province || d.location?.region) && <div className="po-detail-address-row"><span className="po-detail-address-label">Address</span><span className="po-detail-address-value">{[d.location.address, d.location.city, d.location.province, d.location.region].filter(Boolean).join(', ')}</span></div>}
                    {d.location?.contact_number && <div className="po-detail-address-row"><span className="po-detail-address-label">Phone</span><span className="po-detail-address-value">{d.location.contact_number}</span></div>}
                    {d.location?.email          && <div className="po-detail-address-row"><span className="po-detail-address-label">Email</span><span className="po-detail-address-value">{d.location.email}</span></div>}
                    {d.location?.manager_name   && <div className="po-detail-address-row"><span className="po-detail-address-label">Manager</span><span className="po-detail-address-value">{d.location.manager_name}</span></div>}
                  </div>
                </div>

                <div className="po-detail-info-grid">
                  <div className="po-detail-info-item"><span className="po-detail-info-label">CREATED BY</span><span className="po-detail-info-value">{createdByName}</span></div>
                  <div className="po-detail-info-item"><span className="po-detail-info-label">ORDER DATE</span><span className="po-detail-info-value">{isoToMDY(d.order_date)}</span></div>
                  <div className="po-detail-info-item"><span className="po-detail-info-label">EXPECTED DELIVERY</span><span className="po-detail-info-value">{isoToMDY(d.expected_delivery_date)}</span></div>
                  <div className="po-detail-info-item"><span className="po-detail-info-label">TOTAL AMOUNT</span><span className="po-detail-info-value" style={{ color: '#dc2626', fontWeight: 800 }}>₱{amount.toLocaleString('en-PH')}</span></div>
                  {actorName !== '—' && (
                    <div className="po-detail-info-item">
                      <span className="po-detail-info-label">{statusCfg.color === 'rejected' ? 'REVIEWED BY' : 'APPROVED BY'}</span>
                      <span className="po-detail-info-value">{actorName}</span>
                    </div>
                  )}
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">VERIFIED BY</span>
                    <span className="po-detail-info-value">
                      {d?.verification_status === 'verified'
                        ? `${d.verifiedBy ? `${d.verifiedBy.first_name} ${d.verifiedBy.last_name}` : 'Auditor'}${d.verified_at ? ` — ${String(d.verified_at).slice(0, 10)}` : ''}`
                        : d?.verification_status === 'rejected'
                          ? `Rejected: ${d.verification_note || ''}`
                          : '—'}
                    </span>
                  </div>
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
                        {(() => {
                          let idx = 0;
                          return groupPoDetailsByLocation(details).map((grp, gi) => (
                            <React.Fragment key={gi}>
                              <tr>
                                <td colSpan={7} style={{ background: '#eef2f7', fontWeight: 700, fontSize: 11, letterSpacing: '.4px', color: '#334155', textTransform: 'uppercase' }}>
                                  {grp.locationName}
                                  <span style={{ float: 'right', fontWeight: 600, color: '#64748b' }}>₱{Number(grp.subtotal).toLocaleString('en-PH')}</span>
                                </td>
                              </tr>
                              {grp.rows.map((item) => {
                                idx += 1;
                                const ab = item.approvedBy || item.approved_by || null;
                                const detailApprovedBy = ab ? `${ab.first_name || ''} ${ab.last_name || ''}`.trim() || ab.username || '—' : '—';
                                const pType = item.product_type || item.product?.product_type || '';
                                return (
                                  <tr key={item.po_detail_id ?? idx}>
                                    <td className="po-detail-table-idx">{idx}</td>
                                    <td>
                                      <div>{item.product_name || item.product?.product_name || `Item ${idx}`}</div>
                                      {item.product?.applianceDetail?.model_code && (
                                        <small style={{ color: '#94a3b8' }}>{item.product.applianceDetail.model_code}</small>
                                      )}
                                      {ab && <span style={{ display: 'flex', alignItems: 'center', gap: 3, fontSize: 11, color: '#15803d' }}><svg viewBox="0 0 24 24" fill="none" stroke="#15803d" strokeWidth="2.5" width="11" height="11"><polyline points="20 6 9 17 4 12"/></svg>Approved by: {detailApprovedBy}</span>}
                                    </td>
                                    <td style={{ fontSize: 11 }}>
                                      <span className={`po-type-badge ${pType === 'installation_material' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                                        {pType === 'installation_material' ? 'MATERIAL' : 'APPLIANCE'}
                                      </span>
                                    </td>
                                    <td style={{ fontSize: 12, color: '#64748b' }}>{poLineLabel(item)}</td>
                                    <td>{item.quantity_ordered}</td>
                                    <td>₱{Number(item.unit_price || 0).toLocaleString('en-PH')}</td>
                                    <td className="po-detail-table-sub">₱{Number(item.subtotal || 0).toLocaleString('en-PH')}</td>
                                  </tr>
                                );
                              })}
                            </React.Fragment>
                          ));
                        })()}
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
                // Resolve a user value that may be a user object (eager-loaded relation)
                // or a raw integer FK — falls back to allUsers lookup.
                const resolveUserName = (val) => {
                  if (!val) return null;
                  if (typeof val === 'object') {
                    return `${val.first_name || ''} ${val.last_name || ''}`.trim() || val.username || null;
                  }
                  const u = allUsers.find(u => String(u.user_id) === String(val));
                  return u ? `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.username || null : null;
                };

                const poPayload = {
                  po_number:     d.po_number,
                  status:        statusCfg.label,
                  payment_terms: d.payment_terms || 'Net 30',
                  notes:         d.notes         || '',
                  references:    d.references_note || '',
                  company: {
                    name:    companySettings.company_name    || 'Airking Air Conditioning',
                    address: companySettings.company_address || '',
                    email:   companySettings.company_email   || '',
                  },
                  supplier: { name: d.supplier?.supplier_name, address: d.supplier?.address, origin: d.supplier?.origin, region: d.supplier?.region, contact_person: d.supplier?.contact_person, contact_number: d.supplier?.contact_number, email: d.supplier?.email, tin: d.supplier?.tin },
                  ship_to: {
                    name:    d.ship_to_name    || d.location?.location_name,
                    address: d.ship_to_address || [d.location?.address, d.location?.city, d.location?.province, d.location?.region].filter(Boolean).join(', ') || null,
                    contact_number: d.location?.contact_number,
                    email:          d.location?.email,
                    manager_name:   d.location?.manager_name,
                  },
                  prepared_by: createdByName,
                  verified_by: resolveUserName(d.verified_by),
                  // Approved By on the document = whoever approved the PR, not the PO approval-queue step
                  approved_by: resolveUserName(d.purchase_request?.approved_by),
                  order_date:             d.order_date ? String(d.order_date).slice(0, 10) : '—',
                  expected_delivery_date: d.expected_delivery_date ? String(d.expected_delivery_date).slice(0, 10) : '—',
                  items: details.map(item => ({
                    product_code:      item.product?.applianceDetail?.model_code || item.model_code || '—',
                    name:              item.product_name || '—',
                    warehouse_name:    item.location_name || item.location?.location_name || null,
                    unit:              item.unit_abbreviation || (item.product_type === 'appliance' ? 'unit' : item.unit_name || 'pc'),
                    qty:               item.quantity_ordered,
                    unit_price:        item.unit_price,
                    subtotal:          item.subtotal,
                    product_type:      item.product_type,
                    brand_name:        item.brand_name,
                    capacity_rating:   item.capacity_rating,
                    variant:           item.variant,
                    pieces_per_package: item.pieces_per_package,
                    package_unit:      item.package_unit,
                  })),
                  total_amount: amount,
                };
                return (
                  <div className="po-detail-footer">
                    {statusCfg.color === 'pending' && canApprovePO && (
                      <>
                        <button
                          type="button"
                          className="aq-btn-reject"
                          disabled={poActionLoading === d.po_id}
                          onClick={() => handleRejectPO(d)}
                        >
                          REJECT
                        </button>
                        <button
                          type="button"
                          className="aq-btn-approve"
                          disabled={poActionLoading === d.po_id}
                          onClick={() => handleApprovePO(d)}
                        >
                          {poActionLoading === d.po_id ? 'SAVING…' : 'APPROVE'}
                        </button>
                      </>
                    )}
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
