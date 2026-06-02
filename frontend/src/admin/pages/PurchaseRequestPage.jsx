import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import ArchiveButton from '../components/ArchiveButton';
import {
  locationsAPI,
  itemsAPI,
  purchaseRequestsAPI,
  budgetAllocationsAPI,
} from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import PRProgressBar, { getPRStatusText } from '../components/PRProgressBar';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';
import '../styles/archive_management.css';
import DateInput from '../components/DateInput';

// ─── formatters ──────────────────────────────────────────────────────────────

const fmtPeso = (n) =>
  `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const isoToMDY = (s) => {
  if (!s) return '—';
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return `${m}/${d}/${y}`;
};

// ─── PR status helpers ────────────────────────────────────────────────────────

const PR_STATUS = { PENDING: 13, APPROVED: 14, REJECTED: 15, PARTIAL: 16 };

const getPRStatusConfig = (statusId) => {
  switch (statusId) {
    case PR_STATUS.PENDING:  return { label: 'PENDING APPROVAL',  color: 'pending'  };
    case PR_STATUS.APPROVED: return { label: 'APPROVED',          color: 'approved' };
    case PR_STATUS.REJECTED: return { label: 'REJECTED',          color: 'rejected' };
    case PR_STATUS.PARTIAL:  return { label: 'PARTIALLY ORDERED', color: 'partial'  };
    default:                 return { label: 'DRAFT',             color: 'draft'    };
  }
};


// ─── Budget colour helper ─────────────────────────────────────────────────────

const budgetColor = (available, total) => {
  if (!total) return '#64748b';
  const pct = available / total;
  if (pct > 0.5)  return '#16a34a';
  if (pct > 0.10) return '#d97706';
  return '#dc2626';
};

// ─── V5 product display helpers ──────────────────────────────────────────────

// For line items in the create form (camelCase state fields)
const productLabel = (item) => {
  if (item.productType === 'appliance') {
    return [item.brandName, item.capacityRating, item.variant].filter(Boolean).join(' ') || '—';
  }
  if (item.unitName) {
    const ppp = item.piecesPerPackage;
    const pkg = item.packageUnit;
    if (ppp && pkg) return `${item.unitName} · ${ppp}pcs/${pkg}`;
    if (ppp)         return `${item.unitName} · ${ppp}pcs`;
    return item.unitName;
  }
  return '—';
};

// For detail rows from the backend (snake_case flat fields from transformPrDetail)
const detailLabel = (d) => {
  if (d.product_type === 'appliance') {
    return [d.brand_name, d.capacity_rating, d.variant].filter(Boolean).join(' ') || '—';
  }
  const u   = d.unit_name;
  const ppp = d.pieces_per_package;
  const pkg = d.package_unit;
  if (u && ppp && pkg) return `${u} · ${ppp}pcs/${pkg}`;
  if (u && ppp)         return `${u} · ${ppp}pcs`;
  return u || '—';
};

// ─── new line item factory ────────────────────────────────────────────────────

const newLine = () => ({
  id:                  `line-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
  productId:           '',
  productName:         '',
  productType:         '',
  unitId:              '',
  unitName:            '',
  brandName:           '',
  capacityRating:      '',
  variant:             '',
  piecesPerPackage:    null,
  packageUnit:         '',
  qty:                 1,
  estPrice:            0,
  subtotal:            0,
  reorderWarning:      null,
  warningAcknowledged: false,
});

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN COMPONENT
// ═══════════════════════════════════════════════════════════════════════════════

const PurchaseRequestPage = () => {
  const navigate  = useNavigate();
  const location  = useLocation();
  const { user }  = useAuth();
  const roleKey   = getRoleKey(user);
  const isAdmin   = roleKey === ROLES.ADMIN;
  const isIA      = roleKey === ROLES.INVENTORY_ANALYST;
  const canCreate = isAdmin || isIA;  // both roles can submit PRs and convert to PO

  const initialTab = location.state?.tab ?? (isAdmin ? 'approvals' : 'create');
  const [activeTab, setActiveTab] = useState(initialTab);

  // ── Tab 1: Create ─────────────────────────────────────────────────────────
  const [locations,        setLocations]        = useState([]);
  const [loadingLocations, setLoadingLocations] = useState(true);
  const [selectedLocId,    setSelectedLocId]    = useState('');
  const [budget,           setBudget]           = useState(null);
  const [loadingBudget,    setLoadingBudget]    = useState(false);
  const [neededByDate,     setNeededByDate]     = useState(() => {
    const d = new Date(); d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
  });
  const [purpose,   setPurpose]   = useState('');
  const [products,  setProducts]  = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [filterCat,  setFilterCat]  = useState('');
  const [filterBrand, setFilterBrand] = useState('');
  const [lineItems, setLineItems] = useState([newLine()]);
  const [submitting,  setSubmitting]  = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [submitSuccess, setSubmitSuccess] = useState(false);
  const [createdPRNum,  setCreatedPRNum]  = useState('');

  // ── Tab 2: My Requests ────────────────────────────────────────────────────
  const [myPRs,        setMyPRs]        = useState([]);
  const [loadingMyPRs, setLoadingMyPRs] = useState(false);

  // ── Tab 3: Pending Approvals ──────────────────────────────────────────────
  const [pendingPRs,        setPendingPRs]        = useState([]);
  const [loadingPending,    setLoadingPending]    = useState(false);
  const [expandedCards,     setExpandedCards]     = useState(new Set());

  // ── Shared: modals ────────────────────────────────────────────────────────
  const [selectedPR,   setSelectedPR]   = useState(null);
  const [rejectModal,  setRejectModal]  = useState({ open: false, prId: null, reason: '', submitting: false });
  const [actionLoading, setActionLoading] = useState({});

  // ── Load locations ────────────────────────────────────────────────────────
  useEffect(() => {
    let ok = true;
    locationsAPI.getAll({ per_page: 500 })
      .then(res => {
        if (!ok) return;
        const list = Array.isArray(res?.data) ? res.data : [];
        setLocations(list);
        if (list.length) setSelectedLocId(String(list[0].location_id ?? list[0].id ?? ''));
      })
      .catch(console.error)
      .finally(() => { if (ok) setLoadingLocations(false); });
    return () => { ok = false; };
  }, []);

  // ── Load products ─────────────────────────────────────────────────────────
  useEffect(() => {
    let ok = true;
    itemsAPI.getAll({ per_page: 1000 })
      .then(res => {
        if (!ok) return;
        const rows = Array.isArray(res?.data) ? res.data : [];
        setProducts(rows.map(p => ({
          id:              String(p.product_id ?? p.id ?? ''),
          name:            p.product_name || p.name || '',
          code:            p.product_code || p.code || '',
          type:            p.product_type || '',
          categoryId:      String(p.category_id ?? ''),
          categoryName:    p.category?.category_name || '',
          brandId:         String(p.brand_id ?? ''),
          brandName:       p.brand_name || '',
          unitId:          String(p.unit_id ?? ''),
          unitName:        p.unit_name || p.unit?.unit_name || '',
          unitAbbr:        p.unit_abbreviation || p.unit?.unit_abbreviation || '',
          capacityRating:  p.capacity_rating || '',
          variant:         p.variant || '',
          piecesPerPackage: p.pieces_per_package ?? null,
          packageUnit:     p.package_unit || '',
          costPrice:       Number(p.cost_price ?? p.unit_price ?? 0),
        })));
      })
      .catch(console.error)
      .finally(() => { if (ok) setLoadingProducts(false); });
    return () => { ok = false; };
  }, []);

  // ── Fetch budget when location changes ────────────────────────────────────
  useEffect(() => {
    if (!selectedLocId) { setBudget(null); return; }
    let ok = true;
    setLoadingBudget(true);
    setBudget(null);
    budgetAllocationsAPI.getForLocation(selectedLocId)
      .then(res => {
        if (!ok) return;
        const list = Array.isArray(res?.data) ? res.data : [];
        if (!list.length) return;
        // Prefer a budget whose period covers today; otherwise take the first (most recent by period_start)
        const todayStr = new Date().toISOString().slice(0, 10);
        const active = list.find(b =>
          String(b.period_start).slice(0, 10) <= todayStr &&
          String(b.period_end).slice(0, 10)   >= todayStr
        );
        const best = active || list[0];
        return budgetAllocationsAPI.getAvailable(best.budget_id)
          .then(avRes => { if (ok) setBudget(avRes?.data ?? null); });
      })
      .catch(() => { if (ok) setBudget(null); })
      .finally(() => { if (ok) setLoadingBudget(false); });
    return () => { ok = false; };
  }, [selectedLocId]);

  // ── Load tab data ─────────────────────────────────────────────────────────
  useEffect(() => {
    if (activeTab === 'my-requests') {
      setLoadingMyPRs(true);
      purchaseRequestsAPI.getAll({ per_page: 100 })
        .then(res => setMyPRs(Array.isArray(res?.data) ? res.data : []))
        .catch(console.error)
        .finally(() => setLoadingMyPRs(false));
    }
    if (activeTab === 'approvals' && isAdmin) {
      setLoadingPending(true);
      purchaseRequestsAPI.getPending()
        .then(res => setPendingPRs(Array.isArray(res?.data) ? res.data : []))
        .catch(console.error)
        .finally(() => setLoadingPending(false));
    }
  }, [activeTab, isAdmin]);

  // ── Line item helpers ─────────────────────────────────────────────────────
  const addLine = useCallback(() => setLineItems(p => [...p, newLine()]), []);

  const removeLine = useCallback((id) =>
    setLineItems(p => p.filter(li => li.id !== id)), []);

  const updateField = useCallback((id, field, value) => {
    setLineItems(p => p.map(li => {
      if (li.id !== id) return li;
      const u = { ...li, [field]: value };
      if (field === 'qty') {
        u.qty      = Math.max(1, parseInt(value) || 1);
        u.subtotal = u.qty * Number(li.estPrice);
      }
      if (field === 'estPrice') {
        u.estPrice = Number(value) || 0;
        u.subtotal = Number(li.qty) * u.estPrice;
      }
      return u;
    }));
  }, []);

  const handleProductSelect = useCallback(async (lineId, productId) => {
    if (!productId) {
      setLineItems(p => p.map(li =>
        li.id === lineId ? { ...li, productId: '', productName: '', productType: '', unitId: '', unitName: '', estPrice: 0, subtotal: 0, reorderWarning: null } : li
      ));
      return;
    }
    const prod = products.find(p => p.id === productId);
    if (!prod) return;

    setLineItems(p => p.map(li =>
      li.id === lineId ? {
        ...li,
        productId:           prod.id,
        productName:         prod.name,
        productType:         prod.type,
        unitId:              prod.unitId,
        unitName:            prod.unitName,
        brandName:           prod.brandName,
        capacityRating:      prod.capacityRating,
        variant:             prod.variant,
        piecesPerPackage:    prod.piecesPerPackage,
        packageUnit:         prod.packageUnit,
        estPrice:            prod.costPrice,
        subtotal:            Math.max(1, li.qty) * prod.costPrice,
        reorderWarning:      null,
        warningAcknowledged: false,
      } : li
    ));

    // Smart reorder check — non-blocking
    try {
      const res       = await purchaseRequestsAPI.getOpenLinesForProduct(productId);
      const openLines = Array.isArray(res?.data) ? res.data : [];
      if (openLines.length > 0) {
        setLineItems(p => p.map(li =>
          li.id === lineId ? { ...li, reorderWarning: openLines, warningAcknowledged: false } : li
        ));
      }
    } catch (_) { /* non-fatal */ }
  }, [products]);

  const acknowledgeWarning = useCallback((lineId) =>
    setLineItems(p => p.map(li =>
      li.id === lineId ? { ...li, warningAcknowledged: true } : li
    )), []);

  // ── Product filter derived lists ──────────────────────────────────────────
  const categoryOptions = useMemo(() => {
    const seen = new Map();
    products.forEach(p => { if (p.categoryId && !seen.has(p.categoryId)) seen.set(p.categoryId, p.categoryName || p.categoryId); });
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [products]);

  const brandOptions = useMemo(() => {
    const seen = new Map();
    products
      .filter(p => !filterCat || p.categoryId === filterCat)
      .forEach(p => { if (p.brandId && !seen.has(p.brandId)) seen.set(p.brandId, p.brandName || p.brandId); });
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [products, filterCat]);

  const filteredProducts = useMemo(() =>
    products.filter(p =>
      (!filterCat   || p.categoryId === filterCat) &&
      (!filterBrand || p.brandId    === filterBrand)
    ), [products, filterCat, filterBrand]);

  // ── Computed totals ───────────────────────────────────────────────────────
  const estimatedTotal = useMemo(
    () => lineItems.reduce((s, li) => s + Number(li.subtotal || 0), 0),
    [lineItems]
  );
  const budgetAvailable = budget ? Number(budget.available ?? 0) : null;
  const overBudget      = budgetAvailable !== null && estimatedTotal > budgetAvailable;

  // ── Submit PR ─────────────────────────────────────────────────────────────
  const handleSubmitPR = useCallback(async () => {
    setSubmitError('');
    if (!selectedLocId) { setSubmitError('Please select a location.'); return; }
    if (!neededByDate)  { setSubmitError('Please set a needed-by date.'); return; }
    if (neededByDate <= new Date().toISOString().slice(0, 10)) {
      setSubmitError('Needed-by date must be a future date.'); return;
    }
    const validLines = lineItems.filter(li => li.productId && li.qty > 0);
    if (!validLines.length) {
      setSubmitError('Add at least one item with a selected product.'); return;
    }

    setSubmitting(true);
    try {
      const payload = {
        location_id:    Number(selectedLocId),
        needed_by_date: neededByDate,
        purpose:        purpose.trim() || null,
        details:        validLines.map(li => ({
          product_id:      Number(li.productId),
          quantity:        Math.max(1, parseInt(li.qty) || 1),
          estimated_price: Number(li.estPrice) || 0,
        })),
      };
      if (budget?.budget_id) payload.budget_id = budget.budget_id;

      const res = await purchaseRequestsAPI.create(payload);
      setCreatedPRNum(res?.data?.pr_number || 'Purchase Request');
      setSubmitSuccess(true);
      setLineItems([newLine()]);
      setPurpose('');
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to submit purchase request.';
      setSubmitError(msg);
    } finally {
      setSubmitting(false);
    }
  }, [selectedLocId, neededByDate, purpose, lineItems, budget]);

  // ── Archive PR ────────────────────────────────────────────────────────────
  const handleArchivePR = useCallback(async (prId) => {
    await purchaseRequestsAPI.delete(prId);
    setMyPRs(p => p.filter(pr => pr.pr_id !== prId));
    if (selectedPR?.pr_id === prId) setSelectedPR(null);
  }, [selectedPR]);

  // ── Approve / Reject ──────────────────────────────────────────────────────
  const handleApprove = useCallback(async (prId) => {
    setActionLoading(p => ({ ...p, [prId]: 'approving' }));
    try {
      await purchaseRequestsAPI.approve(prId);
      setPendingPRs(p => p.filter(pr => pr.pr_id !== prId));
      if (selectedPR?.pr_id === prId) setSelectedPR(p => p ? { ...p, status_id: PR_STATUS.APPROVED } : null);
    } catch (err) { alert(err?.message || 'Approval failed.'); }
    finally { setActionLoading(p => { const n = { ...p }; delete n[prId]; return n; }); }
  }, [selectedPR]);

  const openRejectModal = useCallback((prId) =>
    setRejectModal({ open: true, prId, reason: '', submitting: false }), []);

  const handleReject = useCallback(async () => {
    if (!rejectModal.reason.trim()) return;
    setRejectModal(p => ({ ...p, submitting: true }));
    try {
      await purchaseRequestsAPI.reject(rejectModal.prId, { rejection_reason: rejectModal.reason });
      setPendingPRs(p => p.filter(pr => pr.pr_id !== rejectModal.prId));
      if (selectedPR?.pr_id === rejectModal.prId)
        setSelectedPR(p => p ? { ...p, status_id: PR_STATUS.REJECTED, rejection_reason: rejectModal.reason } : null);
      setRejectModal({ open: false, prId: null, reason: '', submitting: false });
    } catch (err) {
      alert(err?.message || 'Rejection failed.');
      setRejectModal(p => ({ ...p, submitting: false }));
    }
  }, [rejectModal, selectedPR]);

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER HELPERS
  // ─────────────────────────────────────────────────────────────────────────

  // ── Budget card ───────────────────────────────────────────────────────────
  const renderBudgetCard = () => {
    const selectedLoc = locations.find(l => String(l.location_id ?? l.id ?? '') === String(selectedLocId));
    const locName = selectedLoc?.location_name || 'Selected Location';

    if (loadingBudget) {
      return (
        <div className="pr-budget-card pr-budget-card--loading">
          <div style={{ color: '#64748b', fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="#94a3b8" strokeWidth="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Loading budget for {locName}…
          </div>
        </div>
      );
    }
    if (!budget) {
      return (
        <div className="pr-budget-card pr-budget-card--empty">
          <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="18" height="18" style={{ flexShrink: 0, marginTop: 1 }}>
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <div>
              <div style={{ fontWeight: 700, color: '#92400e', fontSize: 13 }}>No budget found for {locName}</div>
              <div style={{ color: '#78350f', fontSize: 12, marginTop: 3 }}>
                Ask Admin to create a budget allocation for this location in Budget Management.
              </div>
            </div>
          </div>
        </div>
      );
    }
    const total     = Number(budget.total_budget || 0);
    const committed = Number(budget.committed || 0);
    const spent     = Number(budget.spent || 0);
    const available = Number(budget.available ?? (total - committed - spent));
    const avColor   = budgetColor(available, total);

    return (
      <div className="pr-budget-card">
        <div className="pr-budget-card-header">
          <span className="pr-budget-card-title">Budget — {locName}</span>
          <span className="pr-budget-card-period">{budget.period_label || 'Current Period'}</span>
        </div>
        <div className="pr-budget-grid">
          {[
            { label: 'TOTAL BUDGET', value: fmtPeso(total),     color: '#1e293b' },
            { label: 'COMMITTED',    value: fmtPeso(committed),  color: '#d97706' },
            { label: 'SPENT',        value: fmtPeso(spent),      color: '#dc2626' },
            { label: 'AVAILABLE',    value: fmtPeso(available),  color: avColor  },
          ].map(({ label, value, color }) => (
            <div key={label} className="pr-budget-item">
              <span className="pr-budget-label">{label}</span>
              <span className="pr-budget-value" style={{ color }}>{value}</span>
            </div>
          ))}
        </div>
      </div>
    );
  };

  // ── Tab 1: Create Request ─────────────────────────────────────────────────
  const renderCreateTab = () => (
    <div className="pr-create-form-layout">
      {submitSuccess && (
        <div className="po-success-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
          </svg>
          <strong>{createdPRNum}</strong> submitted! Waiting for Admin approval.
          <button type="button" className="po-success-close" onClick={() => setSubmitSuccess(false)} aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      )}

      {/* Request details card */}
      <div className="po-details-card">
        <div className="pr-form-grid">
          <div className="po-field">
            <label className="po-field-label">LOCATION / BRANCH</label>
            <select
              className="po-select"
              value={selectedLocId}
              onChange={e => setSelectedLocId(e.target.value)}
              disabled={loadingLocations}
            >
              {loadingLocations && <option value="">Loading locations…</option>}
              {locations.map(loc => (
                <option key={loc.location_id ?? loc.id} value={String(loc.location_id ?? loc.id ?? '')}>
                  {loc.location_name || loc.name}
                  {loc.location_type ? ` (${loc.location_type})` : ''}
                </option>
              ))}
            </select>
          </div>
          <div className="po-field">
            <label className="po-field-label">NEEDED BY DATE</label>
            <DateInput
              className="po-input"
              value={neededByDate}
              min={new Date(Date.now() + 86400000).toISOString().slice(0, 10)}
              onChange={e => setNeededByDate(e.target.value)}
            />
          </div>
        </div>
        <div className="po-field" style={{ marginTop: 16 }}>
          <label className="po-field-label">PURPOSE / REASON (optional)</label>
          <textarea
            className="po-input"
            style={{ minHeight: 64, resize: 'vertical', fontFamily: 'inherit', fontSize: 13 }}
            value={purpose}
            onChange={e => setPurpose(e.target.value)}
            placeholder="Describe why these items are needed…"
          />
        </div>
      </div>

      {renderBudgetCard()}

      {/* Line items */}
      <div className="po-line-items-card">
        <div className="po-line-items-header">
          <div className="po-line-items-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
              <line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" />
              <line x1="8" y1="18" x2="21" y2="18" /><line x1="3" y1="6" x2="3.01" y2="6" />
              <line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" />
            </svg>
            REQUESTED ITEMS
            <span className="po-line-items-count">{lineItems.length}</span>
          </div>
          <button type="button" className="po-btn po-btn-add-product" onClick={addLine}>
            + ADD ITEM
          </button>
        </div>

        {/* Product filter bar */}
        <div className="pr-filter-bar">
          <span className="pr-filter-label">FILTER:</span>
          <select
            className="po-select pr-filter-select"
            value={filterCat}
            onChange={e => { setFilterCat(e.target.value); setFilterBrand(''); }}
          >
            <option value="">All Categories</option>
            {categoryOptions.map(c => (
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
          <select
            className="po-select pr-filter-select"
            value={filterBrand}
            onChange={e => setFilterBrand(e.target.value)}
          >
            <option value="">All Brands</option>
            {brandOptions.map(b => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
          {(filterCat || filterBrand) && (
            <button type="button" className="pr-filter-clear-btn" onClick={() => { setFilterCat(''); setFilterBrand(''); }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="11" height="11"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              Clear
            </button>
          )}
          <span className="pr-filter-count">
            {filteredProducts.length} product{filteredProducts.length !== 1 ? 's' : ''}
          </span>
        </div>

        <div className="po-table-wrapper">
          <table className="po-table">
            <thead>
              <tr>
                <th>#</th>
                <th>PRODUCT</th>
                <th>TYPE</th>
                <th>DETAILS</th>
                <th>QTY</th>
                <th>EST. PRICE</th>
                <th>SUBTOTAL</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {lineItems.map((li, idx) => (
                <React.Fragment key={li.id}>
                  <tr>
                    <td style={{ color: '#94a3b8', fontSize: 12 }}>{idx + 1}</td>
                    <td style={{ minWidth: 200 }}>
                      <select
                        className="po-select"
                        style={{ fontSize: 12 }}
                        value={li.productId}
                        onChange={e => handleProductSelect(li.id, e.target.value)}
                        disabled={loadingProducts}
                      >
                        <option value="">{loadingProducts ? 'Loading…' : 'Select product…'}</option>
                        {filteredProducts.map(p => (
                          <option key={p.id} value={p.id}>
                            {p.code ? `[${p.code}] ` : ''}{p.name}
                            {p.brandName ? ` — ${p.brandName}` : ''}
                            {p.capacityRating ? ` ${p.capacityRating}` : ''}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td>
                      {li.productType ? (
                        <span className={`po-type-badge ${li.productType === 'consumable' ? 'po-type-part' : 'po-type-appliance'}`}>
                          {li.productType.toUpperCase()}
                        </span>
                      ) : '—'}
                    </td>
                    <td style={{ fontSize: 12, color: '#64748b' }}>{productLabel(li)}</td>
                    <td>
                      <input
                        type="number" min="1"
                        className="po-qty-input"
                        value={li.qty}
                        onChange={e => updateField(li.id, 'qty', e.target.value)}
                      />
                    </td>
                    <td>
                      <input
                        type="number" min="0" step="0.01"
                        className="po-qty-input"
                        style={{ width: 90 }}
                        value={li.estPrice}
                        onChange={e => updateField(li.id, 'estPrice', e.target.value)}
                      />
                    </td>
                    <td><strong>{fmtPeso(li.subtotal)}</strong></td>
                    <td>
                      <button
                        type="button" className="po-remove-btn"
                        onClick={() => removeLine(li.id)}
                        title="Remove"
                      >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                          <polyline points="3 6 5 6 21 6" />
                          <path d="M19 6l-1 14H6L5 6" />
                          <path d="M10 11v6" /><path d="M14 11v6" />
                          <path d="M9 6V4h6v2" />
                        </svg>
                      </button>
                    </td>
                  </tr>

                  {/* Smart reorder warning */}
                  {li.reorderWarning && li.reorderWarning.length > 0 && !li.warningAcknowledged && (
                    <tr>
                      <td colSpan={8} style={{ padding: '0 8px 10px' }}>
                        <div className="pr-reorder-warning">
                          <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="15" height="15" style={{ flexShrink: 0 }}>
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                            <line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
                          </svg>
                          <div style={{ display: 'inline' }}>
                            <strong>You already have open requests for {li.productName}:</strong>
                            {li.reorderWarning.map((w, wi) => (
                              <div key={wi} style={{ marginTop: 4, fontSize: 12, paddingLeft: 4 }}>
                                • <strong>{w.pr_number}</strong> ({w.status}):{' '}
                                {w.quantity_requested} requested →{' '}
                                {w.quantity_ordered} ordered in PO →{' '}
                                <strong style={{ color: '#92400e' }}>{w.quantity_remaining} still remaining</strong>
                                {w.needed_by_date && ` · needed by ${w.needed_by_date}`}
                              </div>
                            ))}
                            {(() => {
                              const totalRemaining = li.reorderWarning.reduce((t, w) => t + (w.quantity_remaining || 0), 0);
                              return (
                                <div style={{ marginTop: 6, fontSize: 12 }}>
                                  Adding more may cause overstock.{' '}
                                  <span style={{ fontStyle: 'italic' }}>Consider reducing your quantity by {totalRemaining}.</span>
                                </div>
                              );
                            })()}
                          </div>
                          <div className="pr-reorder-warning-actions">
                            <button type="button" className="pr-reorder-btn pr-reorder-btn--keep" onClick={() => acknowledgeWarning(li.id)}>
                              Yes, add more
                            </button>
                            <button type="button" className="pr-reorder-btn pr-reorder-btn--remove" onClick={() => removeLine(li.id)}>
                              Remove this line
                            </button>
                          </div>
                        </div>
                      </td>
                    </tr>
                  )}
                  {/* Acknowledged reminder */}
                  {li.reorderWarning && li.reorderWarning.length > 0 && li.warningAcknowledged && (
                    <tr>
                      <td colSpan={8} style={{ padding: '0 8px 8px' }}>
                        <div style={{ background: '#fef9c3', borderRadius: 6, padding: '5px 10px', fontSize: 11, color: '#713f12', display: 'flex', alignItems: 'flex-start', gap: 5 }}>
                          <svg viewBox="0 0 24 24" fill="none" stroke="#92400e" strokeWidth="2" width="12" height="12" style={{ flexShrink: 0, marginTop: 1 }}><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                          <span>Note: {li.reorderWarning.reduce((t, w) => t + (w.quantity_remaining || 0), 0)} unit(s) of {li.productName} already have open demand on other PRs.</span>
                        </div>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              ))}
            </tbody>
          </table>
        </div>

        {/* Footer totals */}
        <div style={{ display: 'flex', justifyContent: 'flex-end', padding: '14px 16px 4px', gap: 24 }}>
          <span style={{ fontSize: 13, color: '#64748b' }}>ESTIMATED TOTAL</span>
          <strong style={{ fontSize: 16, color: '#1e293b' }}>{fmtPeso(estimatedTotal)}</strong>
        </div>

        {overBudget && (
          <div style={{ margin: '0 16px 14px', background: '#fffbeb', border: '1px solid #fbbf24', borderRadius: 8, padding: '10px 14px', fontSize: 13, color: '#92400e', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="16" height="16" style={{ flexShrink: 0, marginTop: 1 }}>
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
              <line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <span>
              Estimated total ({fmtPeso(estimatedTotal)}) exceeds available budget ({fmtPeso(budgetAvailable)}) by{' '}
              <strong>{fmtPeso(estimatedTotal - budgetAvailable)}</strong>. You may still submit — Admin will review.
            </span>
          </div>
        )}
      </div>

      {submitError && <div className="po-submit-error">{submitError}</div>}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 8 }}>
        <button
          type="button"
          className="po-btn po-btn-final-submission"
          onClick={handleSubmitPR}
          disabled={submitting}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
            <path d="M22 2L11 13" /><path d="M22 2l-7 20-4-9-9-4 20-7z" />
          </svg>
          {submitting ? 'SUBMITTING…' : 'SUBMIT PURCHASE REQUEST'}
        </button>
      </div>
    </div>
  );

  // ── Tab 2: My Requests ────────────────────────────────────────────────────
  const renderMyRequestsTab = () => {
    if (loadingMyPRs) return <div className="po-status-loading">Loading requests…</div>;
    if (!myPRs.length) return (
      <div className="po-status-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="40" height="40">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
          <polyline points="14 2 14 8 20 8" />
        </svg>
        <p>No purchase requests yet.</p>
        {canCreate && <span>Use the "Create Request" tab to submit your first PR.</span>}
      </div>
    );

    return (
      <div className="po-status-list">
        {myPRs.map(pr => {
          const cfg       = getPRStatusConfig(pr.status_id);
          const itemCount = pr.details?.length ?? 0;
          const canConvert = pr.status_id === PR_STATUS.APPROVED && !pr.converted_to_po_id && canCreate;
          const requesterName = pr.requester
            ? `${pr.requester.first_name || ''} ${pr.requester.last_name || ''}`.trim() || pr.requester.username
            : '—';

          return (
            <div key={pr.pr_id} className="po-status-card">
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{pr.pr_number}</span>
                    <span className={`po-status-badge po-status-badge--${cfg.color}`}>{cfg.label}</span>
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">LOCATION</span>
                      <span className="po-status-meta-value">{pr.location?.location_name || '—'}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">NEEDED BY</span>
                      <span className="po-status-meta-value">{isoToMDY(pr.needed_by_date)}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">ITEMS</span>
                      <span className="po-status-meta-value">{itemCount} {itemCount === 1 ? 'item' : 'items'}</span>
                    </div>
                    {pr.purpose && (
                      <div className="po-status-meta-item" style={{ gridColumn: '1 / -1' }}>
                        <span className="po-status-meta-label">PURPOSE</span>
                        <span className="po-status-meta-value" style={{ fontStyle: 'italic' }}>{pr.purpose}</span>
                      </div>
                    )}
                  </div>

                  <PRProgressBar pr={pr} />
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>{getPRStatusText(pr)}</div>

                  {pr.status_id === PR_STATUS.REJECTED && pr.rejection_reason && (
                    <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, padding: '8px 12px', marginTop: 10, fontSize: 12, color: '#991b1b' }}>
                      <strong>Rejected:</strong> {pr.rejection_reason}
                    </div>
                  )}
                  {pr.converted_to_po_id && pr.convertedPO?.po_number && (
                    <div style={{ background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8, padding: '8px 12px', marginTop: 10, fontSize: 12, color: '#166534' }}>
                      <strong>Converted to PO:</strong> {pr.convertedPO.po_number}
                    </div>
                  )}

                  {canConvert && (
                    <button
                      type="button"
                      className="pr-convert-btn"
                      onClick={() => navigate('/admin/draft-po-creator', { state: { prId: pr.pr_id, prNumber: pr.pr_number } })}
                    >
                      Convert to PO
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13" style={{ marginLeft: 5 }}><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                    </button>
                  )}
                </div>
                <div className="po-status-card-right">
                  <span className="po-status-total-label">EST. TOTAL</span>
                  <span className="po-status-total-amount">{fmtPeso(pr.estimated_total)}</span>
                  <button type="button" className="po-status-view-btn" onClick={() => setSelectedPR(pr)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" />
                    </svg>
                    VIEW DETAILS
                  </button>
                  <ArchiveButton
                    onArchive={() => handleArchivePR(pr.pr_id)}
                    disabled={![PR_STATUS.APPROVED, PR_STATUS.REJECTED, PR_STATUS.PARTIAL].includes(pr.status_id)}
                  />
                </div>
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // ── Tab 3: Pending Approvals (Admin) ──────────────────────────────────────
  const renderApprovalsTab = () => {
    if (loadingPending) return <div className="po-status-loading">Loading pending approvals…</div>;
    if (!pendingPRs.length) return (
      <div className="po-status-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="1.5" width="40" height="40">
          <polyline points="9 11 12 14 22 4" />
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
        </svg>
        <p>No pending approvals.</p>
        <span>All purchase requests have been reviewed.</span>
      </div>
    );

    return (
      <div className="po-status-list">
        {pendingPRs.map(pr => {
          const requesterName = pr.requester
            ? `${pr.requester.first_name || ''} ${pr.requester.last_name || ''}`.trim() || pr.requester.username || '—'
            : '—';
          const budgetTotal     = Number(pr.budget?.total_budget || 0);
          const budgetCommitted = Number(pr.budget?.committed || 0);
          const budgetSpent     = Number(pr.budget?.spent || 0);
          const budgetAvail     = pr.budget
            ? Number(pr.budget.available ?? (budgetTotal - budgetCommitted - budgetSpent))
            : null;
          const prTotal    = Number(pr.estimated_total || 0);
          const isOver     = budgetAvail !== null && prTotal > budgetAvail;
          const itemCount  = pr.details?.length ?? 0;
          const isExpanded = expandedCards.has(pr.pr_id);
          const isApproving = actionLoading[pr.pr_id] === 'approving';

          return (
            <div key={pr.pr_id} className="po-status-card">
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{pr.pr_number}</span>
                    <span className="po-status-badge po-status-badge--pending">PENDING APPROVAL</span>
                    {isOver && <span className="pr-over-budget-badge">OVER BUDGET</span>}
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">REQUESTED BY</span>
                      <span className="po-status-meta-value">{requesterName}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">LOCATION</span>
                      <span className="po-status-meta-value">{pr.location?.location_name || '—'}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">NEEDED BY</span>
                      <span className="po-status-meta-value">{isoToMDY(pr.needed_by_date)}</span>
                    </div>
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">ITEMS</span>
                      <span className="po-status-meta-value">{itemCount}</span>
                    </div>
                  </div>

                  {/* Budget comparison */}
                  {pr.budget && (
                    <div className="pr-admin-budget-bar">
                      <div>
                        <span style={{ color: '#64748b', fontSize: 11 }}>AVAILABLE</span>
                        <span style={{ marginLeft: 6, fontWeight: 700, color: budgetColor(budgetAvail, budgetTotal) }}>
                          {fmtPeso(budgetAvail)}
                        </span>
                      </div>
                      <div style={{ color: '#cbd5e1' }}>|</div>
                      <div>
                        <span style={{ color: '#64748b', fontSize: 11 }}>PR TOTAL</span>
                        <span style={{ marginLeft: 6, fontWeight: 700, color: isOver ? '#dc2626' : '#16a34a' }}>
                          {fmtPeso(prTotal)}
                        </span>
                      </div>
                    </div>
                  )}

                  {pr.purpose && (
                    <div style={{ fontSize: 12, color: '#64748b', marginTop: 8, fontStyle: 'italic' }}>
                      Purpose: {pr.purpose}
                    </div>
                  )}

                  {/* Expandable item preview */}
                  <button
                    type="button"
                    style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 12, color: '#2563eb', padding: '6px 0 0', display: 'flex', alignItems: 'center', gap: 4 }}
                    onClick={() => setExpandedCards(prev => {
                      const next = new Set(prev);
                      next.has(pr.pr_id) ? next.delete(pr.pr_id) : next.add(pr.pr_id);
                      return next;
                    })}
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="13" height="13"
                      style={{ transform: isExpanded ? 'rotate(180deg)' : 'none', transition: '0.2s' }}>
                      <polyline points="6 9 12 15 18 9" />
                    </svg>
                    {isExpanded ? 'Hide items' : `Show ${itemCount} item(s)`}
                  </button>

                  {isExpanded && Array.isArray(pr.details) && pr.details.length > 0 && (
                    <div className="po-table-wrapper" style={{ marginTop: 8 }}>
                      <table className="po-table" style={{ fontSize: 12 }}>
                        <thead>
                          <tr>
                            <th>PRODUCT</th><th>TYPE</th><th>DETAILS</th><th>QTY</th><th>EST. PRICE</th><th>SUBTOTAL</th>
                          </tr>
                        </thead>
                        <tbody>
                          {pr.details.map((d, di) => (
                            <tr key={d.pr_detail_id ?? di}>
                              <td>
                                <div>{d.product_name || d.product?.product_name || `Item ${di + 1}`}</div>
                                {(d.product_code || d.product?.product_code) && (
                                  <small style={{ color: '#94a3b8' }}>{d.product_code || d.product?.product_code}</small>
                                )}
                              </td>
                              <td>
                                <span className={`po-type-badge ${(d.product_type || d.product?.product_type) === 'consumable' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                                  {(d.product_type || d.product?.product_type) === 'consumable' ? 'CONSUMABLE' : 'APPLIANCE'}
                                </span>
                              </td>
                              <td style={{ color: '#64748b' }}>{detailLabel(d)}</td>
                              <td>{d.quantity}</td>
                              <td>{fmtPeso(d.estimated_price)}</td>
                              <td>{fmtPeso(d.subtotal)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}

                  <div className="pr-action-btns">
                    <button type="button" className="po-status-view-btn" onClick={() => setSelectedPR(pr)}>
                      VIEW DETAILS
                    </button>
                    <button type="button" className="pr-btn-reject" onClick={() => openRejectModal(pr.pr_id)}>
                      REJECT
                    </button>
                    <button
                      type="button"
                      className="pr-btn-approve"
                      onClick={() => handleApprove(pr.pr_id)}
                      disabled={isApproving}
                    >
                      {isApproving ? 'APPROVING…' : 'APPROVE'}
                    </button>
                  </div>
                </div>
                <div className="po-status-card-right">
                  <span className="po-status-total-label">PR TOTAL</span>
                  <span className="po-status-total-amount" style={{ color: isOver ? '#dc2626' : undefined }}>
                    {fmtPeso(prTotal)}
                  </span>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // ── PR Detail Modal ───────────────────────────────────────────────────────
  const renderDetailModal = () => {
    if (!selectedPR) return null;
    const pr            = selectedPR;
    const cfg           = getPRStatusConfig(pr.status_id);
    const requesterName = pr.requester
      ? `${pr.requester.first_name || ''} ${pr.requester.last_name || ''}`.trim() || pr.requester.username || '—'
      : '—';
    const approverName = pr.approver
      ? `${pr.approver.first_name || ''} ${pr.approver.last_name || ''}`.trim() || pr.approver.username
      : null;
    const budgetTotal   = Number(pr.budget?.total_budget || 0);
    const budgetCommit  = Number(pr.budget?.committed || 0);
    const budgetSpent   = Number(pr.budget?.spent || 0);
    const budgetAvail   = pr.budget
      ? Number(pr.budget.available ?? (budgetTotal - budgetCommit - budgetSpent))
      : null;
    const details = Array.isArray(pr.details) ? pr.details : [];

    const isApproving = actionLoading[pr.pr_id] === 'approving';
    const canAct      = pr.status_id === PR_STATUS.PENDING && isAdmin;

    return (
      <Modal isOpen={!!selectedPR} onClose={() => setSelectedPR(null)} title="" hideHeader maxWidth={740}>
        <div className="po-detail-modal">
          {/* Header */}
          <div className="po-detail-head">
            <div className="po-detail-head-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="26" height="26">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" />
              </svg>
            </div>
            <div className="po-detail-head-info">
              <h2 className="po-detail-head-number">{pr.pr_number}</h2>
              <p className="po-detail-head-sub">
                {pr.location?.location_name || '—'} · {isoToMDY(pr.request_date)}
              </p>
            </div>
            <div className="po-detail-head-badges">
              <span className={`po-detail-status-pill po-detail-status-pill--${cfg.color}`}>{cfg.label}</span>
            </div>
            <button type="button" className="po-detail-head-close" onClick={() => setSelectedPR(null)} aria-label="Close">×</button>
          </div>

          <div className="po-detail-body">
            {/* Two-column info */}
            <div className="po-detail-info-grid">
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">REQUESTED BY</span>
                <span className="po-detail-info-value">{requesterName}</span>
              </div>
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">REQUEST DATE</span>
                <span className="po-detail-info-value">{isoToMDY(pr.request_date)}</span>
              </div>
              <div className="po-detail-info-item">
                <span className="po-detail-info-label">NEEDED BY</span>
                <span className="po-detail-info-value">{isoToMDY(pr.needed_by_date)}</span>
              </div>
              {pr.budget && (
                <>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">BUDGET TOTAL</span>
                    <span className="po-detail-info-value">{fmtPeso(budgetTotal)}</span>
                  </div>
                  <div className="po-detail-info-item">
                    <span className="po-detail-info-label">AVAILABLE</span>
                    <span className="po-detail-info-value" style={{ color: budgetColor(budgetAvail, budgetTotal) }}>
                      {fmtPeso(budgetAvail)}
                    </span>
                  </div>
                </>
              )}
              {approverName && (
                <div className="po-detail-info-item">
                  <span className="po-detail-info-label">
                    {pr.status_id === PR_STATUS.REJECTED ? 'REVIEWED BY' : 'APPROVED BY'}
                  </span>
                  <span className="po-detail-info-value">{approverName}</span>
                </div>
              )}
              {pr.purpose && (
                <div className="po-detail-info-item" style={{ gridColumn: '1 / -1' }}>
                  <span className="po-detail-info-label">PURPOSE</span>
                  <span className="po-detail-info-value" style={{ fontStyle: 'italic' }}>{pr.purpose}</span>
                </div>
              )}
            </div>

            {/* Progress bar */}
            <div style={{ margin: '14px 0 6px' }}>
              <PRProgressBar pr={pr} />
              <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>{getPRStatusText(pr)}</div>
            </div>

            {/* Rejected notice */}
            {pr.status_id === PR_STATUS.REJECTED && pr.rejection_reason && (
              <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, padding: '10px 14px', marginTop: 12, fontSize: 13, color: '#991b1b' }}>
                <strong>Rejected:</strong> {pr.rejection_reason}
              </div>
            )}

            {/* Converted notice */}
            {pr.converted_to_po_id && pr.convertedPO?.po_number && (
              <div style={{ background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8, padding: '10px 14px', marginTop: 12, fontSize: 13, color: '#166534' }}>
                <strong>Converted to PO:</strong> {pr.convertedPO.po_number}
              </div>
            )}

            {/* Items table */}
            <div className="po-detail-items-wrap">
              <div className="po-detail-items-title-row">
                <span className="po-detail-items-heading">REQUESTED ITEMS</span>
                <span className="po-detail-items-count">{details.length}</span>
              </div>
              {details.length === 0 ? (
                <p className="po-detail-no-items">No items recorded.</p>
              ) : (
                <table className="po-detail-table">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>PRODUCT</th>
                      <th>TYPE</th>
                      <th>DETAILS</th>
                      <th>REQUESTED</th>
                      <th>IN PO</th>
                      <th>REMAINING</th>
                      <th>SUBTOTAL</th>
                    </tr>
                  </thead>
                  <tbody>
                    {details.map((d, di) => {
                      const remaining   = d.quantity_remaining ?? (d.quantity - (d.quantity_ordered_in_po ?? 0));
                      const orderedInPO = d.quantity_ordered_in_po ?? 0;
                      const hasOpen     = remaining > 0 && (pr.status_id === PR_STATUS.APPROVED || pr.status_id === PR_STATUS.PARTIAL);
                      const pType       = d.product_type || d.product?.product_type || '';
                      return (
                        <tr key={d.pr_detail_id ?? di}
                          style={hasOpen ? { background: '#fffbeb' } : {}}>
                          <td className="po-detail-table-idx">{di + 1}</td>
                          <td>
                            <div>{d.product_name || d.product?.product_name || `Item ${di + 1}`}</div>
                            {(d.product_code || d.product?.product_code) && (
                              <small style={{ color: '#94a3b8' }}>{d.product_code || d.product?.product_code}</small>
                            )}
                          </td>
                          <td style={{ fontSize: 11 }}>
                            <span className={`po-type-badge ${pType === 'consumable' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                              {pType === 'consumable' ? 'CONSUMABLE' : 'APPLIANCE'}
                            </span>
                          </td>
                          <td style={{ fontSize: 11, color: '#64748b' }}>{detailLabel(d)}</td>
                          <td>{d.quantity}</td>
                          <td style={{ color: orderedInPO > 0 ? '#16a34a' : '#94a3b8' }}>{orderedInPO}</td>
                          <td>
                            {hasOpen ? (
                              <span style={{ color: '#d97706', fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                                {remaining}
                                <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="12" height="12"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                              </span>
                            ) : (
                              <span style={{ color: '#94a3b8' }}>{remaining}</span>
                            )}
                          </td>
                          <td className="po-detail-table-sub">{fmtPeso(d.subtotal)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>

            <div className="po-detail-total">
              <span className="po-detail-total-label">ESTIMATED TOTAL</span>
              <span className="po-detail-total-value">{fmtPeso(pr.estimated_total)}</span>
            </div>

            {/* Admin actions */}
            {canAct && (
              <div className="pr-modal-action-row">
                <button type="button" className="pr-btn-reject" onClick={() => { setSelectedPR(null); openRejectModal(pr.pr_id); }}>
                  REJECT
                </button>
                <button type="button" className="pr-btn-approve" onClick={() => handleApprove(pr.pr_id)} disabled={isApproving}>
                  {isApproving ? 'APPROVING…' : 'APPROVE'}
                </button>
              </div>
            )}
          </div>
        </div>
      </Modal>
    );
  };

  // ── Reject Modal ──────────────────────────────────────────────────────────
  const renderRejectModal = () => (
    <Modal isOpen={rejectModal.open} onClose={() => setRejectModal(p => ({ ...p, open: false }))} title="Reject Purchase Request" maxWidth={480}>
      <div style={{ padding: '8px 0' }}>
        <label style={{ display: 'block', fontWeight: 600, fontSize: 13, marginBottom: 8, color: '#374151' }}>
          REJECTION REASON <span style={{ color: '#dc2626' }}>*</span>
        </label>
        <textarea
          style={{ width: '100%', minHeight: 90, border: '1px solid #e2e8f0', borderRadius: 8, padding: '10px 12px', fontSize: 13, fontFamily: 'inherit', resize: 'vertical', boxSizing: 'border-box' }}
          value={rejectModal.reason}
          onChange={e => setRejectModal(p => ({ ...p, reason: e.target.value }))}
          placeholder="Explain why this purchase request is being rejected…"
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
          <button type="button" className="pr-btn-reject" onClick={handleReject} disabled={rejectModal.submitting || !rejectModal.reason.trim()}>
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
        {/* Page header */}
        <div className="po-creator-header-card">
          <div className="po-creator-header-grid">
            <div className="po-creator-header-main">
              <div className="po-creator-cart-wrap po-creator-cart-wrap--doc" aria-hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="28" height="28">
                  <path d="M9 11l3 3L22 4" />
                  <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                </svg>
              </div>
              <div className="po-creator-header-copy">
                <h1 className="po-page-title">PURCHASE REQUESTS</h1>
                <p className="po-page-subtitle">
                  {'Submit, review, and track purchase requests'}
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* Tab navigation */}
        <div className="po-tab-nav">
          {canCreate && (
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
          )}
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'my-requests' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('my-requests')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
              <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
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
                <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
              </svg>
              Pending Approvals
              {pendingPRs.length > 0 && activeTab !== 'approvals' && (
                <span className="pr-pending-badge">{pendingPRs.length}</span>
              )}
            </button>
          )}
        </div>

        {/* Tab content */}
        {activeTab === 'create'       && canCreate  && renderCreateTab()}
        {activeTab === 'my-requests'               && renderMyRequestsTab()}
        {activeTab === 'approvals'    && isAdmin   && renderApprovalsTab()}
      </div>

      {renderDetailModal()}
      {renderRejectModal()}
    </AdminLayout>
  );
};

export default PurchaseRequestPage;
