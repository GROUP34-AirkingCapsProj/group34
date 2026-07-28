import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import ArchiveButton from '../components/ArchiveButton';
import ProductSearchSelect from '../components/ProductSearchSelect';
import RecordHistoryModal from '../components/RecordHistoryModal';
import {
  itemsAPI,
  locationsAPI,
  suppliersAPI,
  purchaseRequestsAPI,
  budgetAllocationsAPI,
  restockAPI,
} from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
import { confirmAction, confirmWithReason } from '../utils/confirm';
import { getRoleKey, ROLES } from '../utils/roles';
import { toOrderQty } from '../utils/restockUnits';
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

// Group PR detail rows by their destination warehouse so each location's
// orders show together under one header. Returns [{ locationName, rows, subtotal }].
const groupDetailsByLocation = (details) => {
  const map = new Map();
  (details || []).forEach((d) => {
    const key  = String(d.location_id ?? d.location?.location_id ?? '__none__');
    const name = d.location_name || d.location?.location_name || 'Unassigned warehouse';
    if (!map.has(key)) map.set(key, { locationName: name, rows: [], subtotal: 0 });
    const grp = map.get(key);
    grp.rows.push(d);
    grp.subtotal += Number(d.subtotal) || 0;
  });
  return [...map.values()];
};

// ─── new row / warehouse block factories ──────────────────────────────────────

const newRow = () => ({
  id:                  `row-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
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

const newBlock = () => ({
  id:          `block-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`,
  warehouseId: '',
  rows:        [newRow()],
});

// ─── recommendation display maps ──────────────────────────────────────────────

const REASON_LABELS = {
  OUT_OF_STOCK:       'Out of stock',
  BELOW_SAFETY_STOCK: 'Below safety stock',
  STOCKOUT_RISK:      'Stockout risk',
  LOW_STOCK:          'Low stock',
  FAST_MOVING:        'Fast moving',
  HIGH_TURNOVER:      'High turnover',
  SLOW_MOVING:        'Slow moving',
  NO_MOVEMENT:        'No movement',
  DEMAND_SHIFT:       'Demand shift',
};

const RECO_PRIORITY_BADGE = {
  CRITICAL: 'ak-badge ak-badge--red',
  HIGH:     'ak-badge ak-badge--amber',
  MEDIUM:   'ak-badge ak-badge--blue',
};

const LOW_STOCK_PAGE_SIZE = 10;
const PR_ITEMS_PAGE_SIZE = 5;

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN COMPONENT
// ═══════════════════════════════════════════════════════════════════════════════

const PurchaseRequestPage = () => {
  const navigate  = useNavigate();
  const location  = useLocation();
  const { user, can }  = useAuth();
  const roleKey   = getRoleKey(user);
  const isAdmin   = roleKey === ROLES.ADMIN;
  // Permission-driven: anyone granted purchase_request_creation.create can submit PRs / convert to PO.
  const canCreate = isAdmin || (typeof can === 'function' && can('purchase_request_creation', 'create'));
  const canApprovePR = isAdmin || (typeof can === 'function' && can('purchase_request_approval', 'approve'));
  const canReadReco   = isAdmin || (typeof can === 'function' && can('po_recommendations', 'read'));
  const canRecalcReco = isAdmin || (typeof can === 'function' && can('po_recommendations', 'create'));
  const canApplyReco  = isAdmin || (typeof can === 'function' && can('po_recommendations', 'update'));

  const initialTab = location.state?.tab ?? (canApprovePR ? 'approvals' : 'create');
  const [activeTab, setActiveTab] = useState(initialTab);

  // ── History tab + details modal ───────────────────────────────────────────
  const [historyPRs,        setHistoryPRs]        = useState([]);   // list shown in the History tab
  const [loadingHistoryPRs, setLoadingHistoryPRs] = useState(false);
  const [historyRecord,     setHistoryRecord]     = useState(null); // the PR whose timeline the modal shows
  const [prHistory,         setPrHistory]         = useState([]);
  const [prHistoryLoading,  setPrHistoryLoading]  = useState(false);

  // Open the details modal for one PR and fetch its full timeline.
  const openPrHistory = (pr) => {
    setHistoryRecord(pr);
    setPrHistory([]);
    setPrHistoryLoading(true);
    purchaseRequestsAPI.history(pr.pr_id)
      .then((res) => setPrHistory(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setPrHistory([]))
      .finally(() => setPrHistoryLoading(false));
  };

  // ── Tab 1: Create ─────────────────────────────────────────────────────────
  const [locations,        setLocations]        = useState([]);
  const [loadingLocations, setLoadingLocations] = useState(true);
  const [suppliers,        setSuppliers]        = useState([]);
  const [loadingSuppliers, setLoadingSuppliers] = useState(true);
  const [selectedSupplierId, setSelectedSupplierId] = useState('');
  // Per-warehouse budget cache: { [locationId]: { loading: bool, budget: object|null } }
  const [budgetsByLoc,     setBudgetsByLoc]     = useState({});
  const fetchedLocRef = useRef(new Set());
  // Per-warehouse recommendation cache: { [locationId]: { loading, recs: [] } }
  const [recsByLoc, setRecsByLoc] = useState({});
  const fetchedRecLocRef = useRef(new Set());
  // Which blocks have the suggestions panel expanded (by block id).
  const [openRecPanels, setOpenRecPanels] = useState(new Set());
  const [lowStockPages, setLowStockPages] = useState({});
  const [prItemPages, setPrItemPages] = useState({});
  const [lowStockSearch, setLowStockSearch] = useState({});
  const [lowStockPriority, setLowStockPriority] = useState({});
  const [neededByDate,     setNeededByDate]     = useState(() => {
    const d = new Date(); d.setDate(d.getDate() + 7);
    return d.toISOString().slice(0, 10);
  });
  const [purpose,   setPurpose]   = useState('');
  const [products,        setProducts]        = useState([]);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [warehouseBlocks, setWarehouseBlocks] = useState([newBlock()]);
  const [submitting,  setSubmitting]  = useState(false);

  // Warehouse-only destinations for the per-line selector
  const warehouseLocations = useMemo(
    () => locations.filter(l => l.location_type === 'warehouse'),
    [locations]
  );

  // ── Tab 2: My Requests ────────────────────────────────────────────────────
  const [myPRs,        setMyPRs]        = useState([]);
  const [loadingMyPRs, setLoadingMyPRs] = useState(false);

  // ── Tab 3: Pending Approvals ──────────────────────────────────────────────
  const [pendingPRs,        setPendingPRs]        = useState([]);
  const [loadingPending,    setLoadingPending]    = useState(false);
  const [pendingPRsLoaded,  setPendingPRsLoaded]  = useState(false);
  const [expandedCards,     setExpandedCards]     = useState(new Set());

  // ── Tab 4: Stock Parameters (migrated suggestions tool) ───────────────────
  const [suggestions,     setSuggestions]     = useState([]);
  const [loadingSug,      setLoadingSug]      = useState(false);
  const [recoRunAt,       setRecoRunAt]       = useState(null);
  const [recalculating,   setRecalculating]   = useState(false);
  const [busySuggestion,  setBusySuggestion]  = useState(null);
  const [sugWarehouse,    setSugWarehouse]    = useState('');
  const [sugCategory,     setSugCategory]     = useState('');
  const [sugBrand,        setSugBrand]        = useState('');
  const [sugSearch,       setSugSearch]       = useState('');
  const [applyingAllSug,  setApplyingAllSug]  = useState(false);

  // ── Shared: modals ────────────────────────────────────────────────────────
  const [selectedPR,   setSelectedPR]   = useState(null);
  const [actionLoading, setActionLoading] = useState({});

  // ── Focus / deep-link ─────────────────────────────────────────────────────
  const focusPrId = useFocusTarget('pr');
  const focusHandledRef = useRef(false);
  const [prPulse, firePrPulse] = useFocusPulse();

  // ── Load locations ────────────────────────────────────────────────────────
  useEffect(() => {
    let ok = true;
    locationsAPI.getAll({ per_page: 500 })
      .then(res => {
        if (!ok) return;
        const list = Array.isArray(res?.data) ? res.data : [];
        setLocations(list);
      })
      .catch(console.error)
      .finally(() => { if (ok) setLoadingLocations(false); });
    return () => { ok = false; };
  }, []);

  // ── Load suppliers ────────────────────────────────────────────────────────
  useEffect(() => {
    let ok = true;
    suppliersAPI.getAll({ per_page: 500 })
      .then(res => {
        if (!ok) return;
        const list = Array.isArray(res?.data) ? res.data : [];
        setSuppliers(list);
      })
      .catch(console.error)
      .finally(() => { if (ok) setLoadingSuppliers(false); });
    return () => { ok = false; };
  }, []);

  // ── Load all active products once (supplier no longer filters products) ──
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
          recommendedStocks: Number(p.recommended_stocks ?? 0),
          safetyStock:       Number(p.safety_stock ?? 0),
          maxStockLevel:     Number(p.max_stock_level ?? 0),
        })));
      })
      .catch(console.error)
      .finally(() => { if (ok) setLoadingProducts(false); });
    return () => { ok = false; };
  }, []);

  // ── Distinct warehouses currently used across warehouse blocks ────────────
  const distinctBlockLocIds = useMemo(() => {
    const ids = new Set();
    warehouseBlocks.forEach(b => { if (b.warehouseId) ids.add(String(b.warehouseId)); });
    return [...ids];
  }, [warehouseBlocks]);

  // Fast product lookup by id for the per-row "suggested quantity" hint.
  const productById = useMemo(
    () => new Map(products.map(p => [p.id, p])),
    [products]
  );

  // ── Stock Parameters tab: filter option lists + filtered rows ─────────────
  // Category/brand come from the loaded product list (global per product);
  // warehouses come from each suggestion's `warehouses` (products stocked there).
  const sugCategoryOptions = useMemo(() => {
    const map = new Map();
    suggestions.forEach(s => {
      const p = productById.get(String(s.product_id));
      if (p?.categoryId) map.set(String(p.categoryId), p.categoryName || `#${p.categoryId}`);
    });
    return [...map.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [suggestions, productById]);

  const sugBrandOptions = useMemo(() => {
    const map = new Map();
    suggestions.forEach(s => {
      const p = productById.get(String(s.product_id));
      if (p?.brandId) map.set(String(p.brandId), p.brandName || `#${p.brandId}`);
    });
    return [...map.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [suggestions, productById]);

  const filteredSuggestions = useMemo(() => suggestions.filter(s => {
    const p = productById.get(String(s.product_id));
    const search = sugSearch.trim().toLowerCase();
    const productName = s.product?.product_name || p?.name || '';
    const productCode = s.product?.product_code || p?.code || '';
    if (search && ![productName, productCode].some(value => String(value).toLowerCase().includes(search))) return false;
    if (sugCategory && String(p?.categoryId ?? '') !== sugCategory) return false;
    if (sugBrand && String(p?.brandId ?? '') !== sugBrand) return false;
    if (sugWarehouse && !(s.warehouses || []).some(w => String(w.location_id) === sugWarehouse)) return false;
    return true;
  }), [suggestions, productById, sugCategory, sugBrand, sugWarehouse, sugSearch]);

  // ── Fetch (and cache) the active budget for each distinct warehouse ───────
  useEffect(() => {
    distinctBlockLocIds.forEach(locId => {
      if (fetchedLocRef.current.has(locId)) return;
      fetchedLocRef.current.add(locId);
      setBudgetsByLoc(prev => ({ ...prev, [locId]: { loading: true, budget: null } }));
      budgetAllocationsAPI.getForLocation(locId)
        .then(res => {
          const list = Array.isArray(res?.data) ? res.data : [];
          if (!list.length) return null;
          // Prefer a budget whose period covers today; otherwise take the first (most recent by period_start)
          const todayStr = new Date().toISOString().slice(0, 10);
          const active = list.find(b =>
            String(b.period_start).slice(0, 10) <= todayStr &&
            String(b.period_end).slice(0, 10)   >= todayStr
          );
          const best = active || list[0];
          return budgetAllocationsAPI.getAvailable(best.budget_id);
        })
        .then(avRes => {
          setBudgetsByLoc(prev => ({ ...prev, [locId]: { loading: false, budget: avRes?.data ?? null } }));
        })
        .catch(() => {
          setBudgetsByLoc(prev => ({ ...prev, [locId]: { loading: false, budget: null } }));
        });
    });
  }, [distinctBlockLocIds]);

  // ── Fetch (and cache) low-stock recommendations for each distinct warehouse ─
  useEffect(() => {
    distinctBlockLocIds.forEach(locId => {
      if (fetchedRecLocRef.current.has(locId)) return;
      fetchedRecLocRef.current.add(locId);
      setRecsByLoc(prev => ({ ...prev, [locId]: { loading: true, recs: [] } }));
      purchaseRequestsAPI.getLowStock(locId)
        .then(res => {
          const recs = Array.isArray(res?.data) ? res.data : [];
          setRecsByLoc(prev => ({ ...prev, [locId]: { loading: false, recs } }));
        })
        .catch(() => {
          setRecsByLoc(prev => ({ ...prev, [locId]: { loading: false, recs: [] } }));
        });
    });
  }, [distinctBlockLocIds]);

  // ── Load tab data ─────────────────────────────────────────────────────────
  useEffect(() => {
    if (activeTab === 'my-requests') {
      setLoadingMyPRs(true);
      purchaseRequestsAPI.getAll({ per_page: 100 })
        .then(res => setMyPRs(Array.isArray(res?.data) ? res.data : []))
        .catch(console.error)
        .finally(() => setLoadingMyPRs(false));
    }
    if (activeTab === 'approvals' && canApprovePR) {
      setLoadingPending(true);
      purchaseRequestsAPI.getPending()
        .then(res => setPendingPRs(Array.isArray(res?.data) ? res.data : []))
        .catch(console.error)
        .finally(() => { setLoadingPending(false); setPendingPRsLoaded(true); });
    }
    if (activeTab === 'stock-parameters' && canReadReco) {
      setLoadingSug(true);
      Promise.all([
        restockAPI.getSuggestions('pending'),
        restockAPI.getRecommendations({}),
      ])
        .then(([sugRes, recRes]) => {
          setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
          setRecoRunAt(recRes?.data?.run_at || null);
        })
        .catch(console.error)
        .finally(() => setLoadingSug(false));
    }
    if (activeTab === 'history') {
      setLoadingHistoryPRs(true);
      purchaseRequestsAPI.getAll({ per_page: 100 })
        .then(res => setHistoryPRs(Array.isArray(res?.data) ? res.data : []))
        .catch(console.error)
        .finally(() => setLoadingHistoryPRs(false));
    }
  }, [activeTab, canApprovePR, canReadReco]);

  // ── Focus deep-link: switch approvers to the approvals tab ───────────────
  useEffect(() => {
    if (focusPrId && !focusHandledRef.current && canApprovePR) setActiveTab('approvals');
  }, [focusPrId, canApprovePR]);

  // ── Focus deep-link: open the PR once the pending list has loaded ─────────
  useEffect(() => {
    if (!focusPrId || focusHandledRef.current) return;
    if (!pendingPRsLoaded) return;
    focusHandledRef.current = true;
    const pr =
      findById(pendingPRs, ['pr_id', 'id'], focusPrId) ||
      findById(myPRs, ['pr_id', 'id'], focusPrId);
    if (pr) { setSelectedPR(pr); firePrPulse(); }
    else toast.error('That purchase request is not available.');
  }, [focusPrId, pendingPRsLoaded, pendingPRs, myPRs, firePrPulse]);

  // ── Warehouse block helpers ────────────────────────────────────────────────
  const addBlock = useCallback(() => setWarehouseBlocks(p => [...p, newBlock()]), []);

  const removeBlock = useCallback((blockId) =>
    setWarehouseBlocks(p => (p.length > 1 ? p.filter(b => b.id !== blockId) : p)), []);

  const setBlockWarehouse = useCallback((blockId, warehouseId) => {
    setWarehouseBlocks(p => p.map(b => (b.id === blockId ? { ...b, warehouseId } : b)));
    setLowStockPages(p => ({ ...p, [blockId]: 1 }));
    setPrItemPages(p => ({ ...p, [blockId]: 1 }));
  }, []);

  const addRow = useCallback((blockId) =>
    setWarehouseBlocks(p => p.map(b => (b.id === blockId ? { ...b, rows: [...b.rows, newRow()] } : b))), []);

  const removeRow = useCallback((blockId, rowId) =>
    setWarehouseBlocks(p => p.map(b => (b.id === blockId ? { ...b, rows: b.rows.filter(r => r.id !== rowId) } : b))), []);

  const updateRowField = useCallback((blockId, rowId, field, value) => {
    setWarehouseBlocks(p => p.map(b => {
      if (b.id !== blockId) return b;
      return {
        ...b,
        rows: b.rows.map(r => {
          if (r.id !== rowId) return r;
          const u = { ...r, [field]: value };
          if (field === 'qty') {
            u.qty      = Math.max(1, parseInt(value) || 1);
            u.subtotal = u.qty * Number(r.estPrice);
          }
          if (field === 'estPrice') {
            u.estPrice = Number(value) || 0;
            u.subtotal = Number(r.qty) * u.estPrice;
          }
          return u;
        }),
      };
    }));
  }, []);

  const handleProductSelect = useCallback(async (blockId, rowId, productId) => {
    if (!productId) {
      setWarehouseBlocks(p => p.map(b => (b.id !== blockId ? b : {
        ...b,
        rows: b.rows.map(r => (r.id === rowId
          ? { ...r, productId: '', productName: '', productType: '', unitId: '', unitName: '', estPrice: 0, subtotal: 0, reorderWarning: null }
          : r)),
      })));
      return;
    }
    const prod = products.find(p => p.id === productId);
    if (!prod) return;

    setWarehouseBlocks(p => p.map(b => (b.id !== blockId ? b : {
      ...b,
      rows: b.rows.map(r => (r.id === rowId ? {
        ...r,
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
        subtotal:            Math.max(1, r.qty) * prod.costPrice,
        reorderWarning:      null,
        warningAcknowledged: false,
      } : r)),
    })));

    // Smart reorder check — non-blocking
    try {
      const res       = await purchaseRequestsAPI.getOpenLinesForProduct(productId);
      const openLines = Array.isArray(res?.data) ? res.data : [];
      if (openLines.length > 0) {
        setWarehouseBlocks(p => p.map(b => (b.id !== blockId ? b : {
          ...b,
          rows: b.rows.map(r => (r.id === rowId ? { ...r, reorderWarning: openLines, warningAcknowledged: false } : r)),
        })));
      }
    } catch (_) { /* non-fatal */ }
  }, [products]);

  // ── Add a recommended low-stock item as a prefilled row in a block ─────────
  const addRecommendedItem = useCallback(async (blockId, rec) => {
    const pid  = String(rec.product_id);
    const prod = products.find(p => p.id === pid);
    if (!prod) { toast.error('Product not available', 'Reload the product list and try again.'); return; }

    // rec.recommended_quantity is PIECES; the PR qty field means PACKAGES for
    // materials. Prefer the API's order_packages; fall back to local conversion.
    const pieces  = Math.max(1, Number(rec.recommended_quantity) || 1);
    const qty     = Math.max(1, Number(rec.order_packages) || toOrderQty(pieces, prod));
    const newId   = `row-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
    const rowData = {
      id:                  newId,
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
      qty,
      estPrice:            prod.costPrice,
      subtotal:            qty * prod.costPrice,
      reorderWarning:      null,
      warningAcknowledged: false,
    };

    setWarehouseBlocks(p => p.map(b => {
      if (b.id !== blockId) return b;
      // If the first row is still empty, fill it instead of appending.
      const firstEmpty = b.rows.length === 1 && !b.rows[0].productId;
      return { ...b, rows: firstEmpty ? [rowData] : [...b.rows, rowData] };
    }));
    setPrItemPages(p => ({ ...p, [blockId]: Number.MAX_SAFE_INTEGER }));

    // Non-blocking smart-reorder check, same as handleProductSelect.
    try {
      const res       = await purchaseRequestsAPI.getOpenLinesForProduct(pid);
      const openLines = Array.isArray(res?.data) ? res.data : [];
      if (openLines.length > 0) {
        setWarehouseBlocks(p => p.map(b => (b.id !== blockId ? b : {
          ...b,
          rows: b.rows.map(r => (r.id === newId ? { ...r, reorderWarning: openLines, warningAcknowledged: false } : r)),
        })));
      }
    } catch (_) { /* non-fatal */ }
  }, [products]);

  const acknowledgeWarning = useCallback((blockId, rowId) =>
    setWarehouseBlocks(p => p.map(b => (b.id !== blockId ? b : {
      ...b,
      rows: b.rows.map(r => (r.id === rowId ? { ...r, warningAcknowledged: true } : r)),
    }))), []);

  // ── Computed totals ───────────────────────────────────────────────────────
  const estimatedTotal = useMemo(
    () => warehouseBlocks.reduce((s, b) => s + b.rows.reduce((s2, r) => s2 + Number(r.subtotal || 0), 0), 0),
    [warehouseBlocks]
  );

  // ── Per-warehouse subtotal + budget-group status ──────────────────────────
  const blockSubtotalsByLoc = useMemo(() => {
    const map = {};
    warehouseBlocks.forEach(b => {
      if (!b.warehouseId) return;
      const locId = String(b.warehouseId);
      const sub   = b.rows.reduce((s, r) => s + Number(r.subtotal || 0), 0);
      map[locId] = (map[locId] || 0) + sub;
    });
    return map;
  }, [warehouseBlocks]);

  const warehouseGroupStatus = useMemo(() => distinctBlockLocIds.map(locId => {
    const entry     = budgetsByLoc[locId];
    const loading   = entry?.loading ?? true;
    const budget    = entry?.budget ?? null;
    const subtotal  = blockSubtotalsByLoc[locId] || 0;
    const available = budget ? Number(budget.available ?? 0) : null;
    const verified  = budget?.verification_status === 'verified';
    const missing   = !loading && !budget;
    const over      = available !== null && subtotal > available;
    const loc       = locations.find(l => String(l.location_id ?? l.id ?? '') === locId);
    const locName   = loc?.location_name || loc?.name || `Warehouse #${locId}`;
    const ok        = !loading && !missing && verified && !over;
    return { locId, locName, loading, budget, subtotal, available, verified, missing, over, ok };
  }), [distinctBlockLocIds, budgetsByLoc, blockSubtotalsByLoc, locations]);

  const hasBlockingWarehouseGroup = warehouseGroupStatus.some(g => !g.ok);

  // ── Warehouse dropdown options per block (exclude warehouses used by others) ──
  const availableWarehousesForBlock = useCallback((blockId) => {
    const usedByOthers = new Set(
      warehouseBlocks.filter(b => b.id !== blockId && b.warehouseId).map(b => String(b.warehouseId))
    );
    return warehouseLocations.filter(loc => !usedByOthers.has(String(loc.location_id ?? loc.id ?? '')));
  }, [warehouseBlocks, warehouseLocations]);

  const allWarehousesUsed = distinctBlockLocIds.length >= warehouseLocations.length && warehouseLocations.length > 0;

  const hasAnyValidRow = useMemo(
    () => warehouseBlocks.some(b => b.rows.some(r => r.productId && Number(r.qty) > 0)),
    [warehouseBlocks]
  );

  const hasRowsWithoutWarehouse = useMemo(
    () => warehouseBlocks.some(b => !b.warehouseId && b.rows.some(r => r.productId && Number(r.qty) > 0)),
    [warehouseBlocks]
  );

  // ── Submit PR ─────────────────────────────────────────────────────────────
  const handleSubmitPR = useCallback(async () => {
    if (!selectedSupplierId) { toast.error("Couldn't send request", 'Please select a supplier.'); return; }
    if (!neededByDate)  { toast.error("Couldn't send request", 'Please set a needed-by date.'); return; }
    if (neededByDate <= new Date().toISOString().slice(0, 10)) {
      toast.error("Couldn't send request", 'Needed-by date must be a future date.'); return;
    }
    if (!hasAnyValidRow) {
      toast.error("Couldn't send request", 'Add at least one item with a selected product.'); return;
    }
    if (hasRowsWithoutWarehouse) {
      toast.error("Couldn't send request", 'Select a destination warehouse for every warehouse block that has items.'); return;
    }

    // Verify each warehouse group used by a block with valid rows is fundable
    const usedLocIds = [...new Set(
      warehouseBlocks
        .filter(b => b.warehouseId && b.rows.some(r => r.productId && Number(r.qty) > 0))
        .map(b => String(b.warehouseId))
    )];
    for (const locId of usedLocIds) {
      const group = warehouseGroupStatus.find(g => g.locId === locId);
      if (!group || group.loading) {
        toast.error("Couldn't send request", 'Still loading budget information — please wait a moment and try again.');
        return;
      }
      if (group.missing) {
        toast.error("Couldn't send request", `No budget found for ${group.locName}. Ask Admin to create one in Budget Management.`);
        return;
      }
      if (!group.verified) {
        toast.error("Couldn't send request", `The budget for ${group.locName} has not been verified by an auditor yet.`);
        return;
      }
      if (group.over) {
        toast.error(
          "Couldn't send request",
          `Estimated total ${fmtPeso(group.subtotal)} for ${group.locName} exceeds its available budget ${fmtPeso(group.available)}.`
        );
        return;
      }
    }

    setSubmitting(true);
    try {
      const details = [];
      warehouseBlocks.forEach(b => {
        b.rows.forEach(r => {
          if (r.productId && Number(r.qty) > 0) {
            details.push({
              product_id:      Number(r.productId),
              location_id:     Number(b.warehouseId),
              quantity:        Math.max(1, parseInt(r.qty) || 1),
              estimated_price: Number(r.estPrice) || 0,
            });
          }
        });
      });

      const payload = {
        supplier_id:    Number(selectedSupplierId),
        needed_by_date: neededByDate,
        purpose:        purpose.trim() || null,
        details,
      };

      const res = await purchaseRequestsAPI.create(payload);
      const prNum = res?.data?.pr_number || 'Purchase Request';
      toast.success('Purchase Request sent', res?.message || `${prNum} — waiting for admin approval`);
      setWarehouseBlocks([newBlock()]);
      setPurpose('');
    } catch (err) {
      const msg = err?.errors
        ? Object.values(err.errors).flat().join(' ')
        : err?.message || 'Failed to submit purchase request.';
      toast.error("Couldn't send request", msg);
    } finally {
      setSubmitting(false);
    }
  }, [selectedSupplierId, neededByDate, purpose, warehouseBlocks, warehouseGroupStatus, hasAnyValidRow, hasRowsWithoutWarehouse]);

  // ── Archive PR ────────────────────────────────────────────────────────────
  const handleArchivePR = useCallback(async (prId) => {
    await purchaseRequestsAPI.delete(prId);
    setMyPRs(p => p.filter(pr => pr.pr_id !== prId));
    if (selectedPR?.pr_id === prId) setSelectedPR(null);
  }, [selectedPR]);

  // ── Approve / Reject ──────────────────────────────────────────────────────
  const handleApprove = useCallback(async (prId) => {
    if (!(await confirmAction({
      title: 'Approve this purchase request?',
      confirmText: 'Approve',
      tone: 'primary',
    }))) return;
    setActionLoading(p => ({ ...p, [prId]: 'approving' }));
    try {
      await purchaseRequestsAPI.approve(prId);
      setPendingPRs(p => p.filter(pr => pr.pr_id !== prId));
      if (selectedPR?.pr_id === prId) setSelectedPR(p => p ? { ...p, status_id: PR_STATUS.APPROVED } : null);
      toast.success('Request approved');
    } catch (err) { toast.error("Couldn't approve request", err?.message || 'Approval failed.'); }
    finally { setActionLoading(p => { const n = { ...p }; delete n[prId]; return n; }); }
  }, [selectedPR]);

  const handleReject = useCallback(async (prId) => {
    const { confirmed, reason } = await confirmWithReason({
      title: 'Reject this purchase request?',
      confirmText: 'Reject',
      tone: 'danger',
      reasonRequired: true,
      reasonLabel: 'Reason for rejection',
      reasonPlaceholder: 'Explain why this purchase request is being rejected…',
    });
    if (!confirmed) return;
    setActionLoading(p => ({ ...p, [prId]: 'rejecting' }));
    try {
      await purchaseRequestsAPI.reject(prId, { rejection_reason: reason });
      setPendingPRs(p => p.filter(pr => pr.pr_id !== prId));
      if (selectedPR?.pr_id === prId)
        setSelectedPR(p => p ? { ...p, status_id: PR_STATUS.REJECTED, rejection_reason: reason } : null);
      toast.success('Request rejected');
    } catch (err) {
      toast.error("Couldn't reject request", err?.message || 'Rejection failed.');
    } finally { setActionLoading(p => { const n = { ...p }; delete n[prId]; return n; }); }
  }, [selectedPR]);

  // ── Stock parameter suggestions: recalculate / apply / dismiss ────────────
  const handleRecalcReco = useCallback(async () => {
    setRecalculating(true);
    try {
      const res = await restockAPI.recalculate();
      setRecoRunAt(res?.data?.run_at || null);
      const sugRes = await restockAPI.getSuggestions('pending');
      setSuggestions(Array.isArray(sugRes?.data) ? sugRes.data : []);
      // Invalidate inline caches so Create Request reflects the fresh batch.
      fetchedRecLocRef.current = new Set();
      setRecsByLoc({});
      toast.success('Recommendations recalculated');
    } catch (err) {
      toast.error('Recalculation failed', err?.message);
    } finally {
      setRecalculating(false);
    }
  }, []);

  const handleApplySuggestion = useCallback(async (s) => {
    const ok = await confirmAction({
      title: `Apply new stock parameters to ${s.product?.product_name || 'this product'}?`,
      text:
        `Recommended ${s.current_recommended} → ${s.suggested_recommended}, ` +
        `Safety ${s.current_safety ?? 0} → ${s.suggested_safety ?? 0}, ` +
        `Max ${s.current_max ?? 0} → ${s.suggested_max ?? 0}`,
      confirmText: 'Apply',
      tone: 'primary',
    });
    if (!ok) return;
    setBusySuggestion(s.suggestion_id);
    try {
      await restockAPI.applySuggestion(s.suggestion_id);
      setSuggestions(prev => prev.filter(x => x.suggestion_id !== s.suggestion_id));
      toast.success('Stock parameters updated');
    } catch (err) {
      toast.error('Could not apply suggestion', err?.message);
    } finally {
      setBusySuggestion(null);
    }
  }, []);

  const handleDismissSuggestion = useCallback(async (s) => {
    setBusySuggestion(s.suggestion_id);
    try {
      await restockAPI.dismissSuggestion(s.suggestion_id);
      setSuggestions(prev => prev.filter(x => x.suggestion_id !== s.suggestion_id));
      toast.info('Suggestion dismissed');
    } catch (err) {
      toast.error('Could not dismiss suggestion', err?.message);
    } finally {
      setBusySuggestion(null);
    }
  }, []);

  const handleApplyAllSuggestions = useCallback(async () => {
    if (filteredSuggestions.length === 0) return;
    const targets = [...filteredSuggestions];
    const ok = await confirmAction({
      title: `Apply ${targets.length} filtered stock parameter suggestion${targets.length === 1 ? '' : 's'}?`,
      text: 'This updates the recommended, safety, and maximum stock levels for every product currently matching the filters.',
      confirmText: `Apply all ${targets.length}`,
      tone: 'primary',
    });
    if (!ok) return;

    setApplyingAllSug(true);
    try {
      const results = await Promise.allSettled(
        targets.map(s => restockAPI.applySuggestion(s.suggestion_id))
      );
      const appliedIds = new Set(
        targets
          .filter((_, index) => results[index].status === 'fulfilled')
          .map(s => s.suggestion_id)
      );
      setSuggestions(prev => prev.filter(s => !appliedIds.has(s.suggestion_id)));

      const failed = results.length - appliedIds.size;
      if (failed === 0) {
        toast.success(`${appliedIds.size} stock parameter suggestion${appliedIds.size === 1 ? '' : 's'} applied`);
      } else {
        toast.error(
          'Some suggestions could not be applied',
          `${appliedIds.size} applied and ${failed} failed. Failed suggestions remain in the list.`
        );
      }
    } finally {
      setApplyingAllSug(false);
    }
  }, [filteredSuggestions]);

  // ─────────────────────────────────────────────────────────────────────────
  // RENDER HELPERS
  // ─────────────────────────────────────────────────────────────────────────

  // ── Single warehouse-block budget card ─────────────────────────────────────
  const renderBudgetCard = (group) => {
    if (!group) return null;
    if (group.loading) {
      return (
        <div className="pr-budget-card pr-budget-card--loading">
          <Loader variant="inline" label={`Loading budget for ${group.locName}`} />
        </div>
      );
    }
    if (group.missing) {
      return (
        <div className="pr-budget-card pr-budget-card--empty">
          <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="18" height="18" style={{ flexShrink: 0, marginTop: 1 }}>
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <div>
              <div style={{ fontWeight: 700, color: '#92400e', fontSize: 13 }}>No budget found for {group.locName}</div>
              <div style={{ color: '#78350f', fontSize: 12, marginTop: 3 }}>
                Ask Admin to create a budget allocation for this warehouse in Budget Management.
              </div>
            </div>
          </div>
        </div>
      );
    }

    const budget    = group.budget;
    const total     = Number(budget.total_budget || 0);
    const committed = Number(budget.committed || 0);
    const spent     = Number(budget.spent || 0);
    const available = Number(budget.available ?? (total - committed - spent));
    const avColor   = budgetColor(available, total);
    const badge     = budget.verification_status === 'verified'
      ? { label: 'VERIFIED', color: '#16a34a', bg: '#f0fdf4' }
      : budget.verification_status === 'rejected'
        ? { label: 'REJECTED', color: '#dc2626', bg: '#fef2f2' }
        : { label: 'AWAITING VERIFICATION', color: '#d97706', bg: '#fffbeb' };

    return (
      <div className="pr-budget-card">
        <div className="pr-budget-card-header">
          <span className="pr-budget-card-title">Budget — {group.locName}</span>
          <span
            style={{ fontSize: 11, fontWeight: 700, color: badge.color, background: badge.bg, padding: '3px 8px', borderRadius: 999 }}
          >
            {badge.label}
          </span>
        </div>
        <div className="pr-budget-grid">
          {[
            { label: 'TOTAL BUDGET', value: fmtPeso(total),          color: '#1e293b' },
            { label: 'COMMITTED',    value: fmtPeso(committed),      color: '#d97706' },
            { label: 'SPENT',        value: fmtPeso(spent),          color: '#dc2626' },
            { label: 'AVAILABLE',    value: fmtPeso(available),      color: avColor  },
            { label: 'THIS REQUEST', value: fmtPeso(group.subtotal), color: group.over ? '#dc2626' : '#1e293b' },
          ].map(({ label, value, color }) => (
            <div key={label} className="pr-budget-item">
              <span className="pr-budget-label">{label}</span>
              <span className="pr-budget-value" style={{ color }}>{value}</span>
            </div>
          ))}
        </div>
        {group.over && (
          <div style={{ margin:'10px 0 0', background:'#fef2f2', border:'1px solid #fca5a5', borderRadius:8, padding:'8px 12px', fontSize:13, color:'#991b1b' }}>
            This request's items for {group.locName} ({fmtPeso(group.subtotal)}) exceed the available budget ({fmtPeso(available)}).
          </div>
        )}
        {!group.over && budget.verification_status !== 'verified' && (
          <div style={{ margin:'10px 0 0', background:'#fffbeb', border:'1px solid #fbbf24', borderRadius:8, padding:'8px 12px', fontSize:13, color:'#92400e' }}>
            {budget.verification_status === 'rejected'
              ? `This budget was rejected by the auditor${budget.verification_note ? `: ${budget.verification_note}` : ''}.`
              : 'This budget is awaiting auditor verification — you cannot submit a request against it yet.'}
          </div>
        )}
      </div>
    );
  };

  // ── Tab 1: Create Request ─────────────────────────────────────────────────
  const renderCreateTab = () => (
    <div className="pr-create-form-layout">
      {/* Request details card */}
      <div className="po-details-card">
        <div className="pr-form-grid">
          <div className="po-field">
            <label className="po-field-label">SUPPLIER</label>
            <select
              className="po-select"
              value={selectedSupplierId}
              onChange={e => setSelectedSupplierId(e.target.value)}
              disabled={loadingSuppliers}
            >
              <option value="">{loadingSuppliers ? 'Loading suppliers…' : 'Select supplier…'}</option>
              {suppliers.map(s => (
                <option key={s.supplier_id ?? s.id} value={String(s.supplier_id ?? s.id ?? '')}>
                  {s.supplier_name || s.name}
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

      {/* Warehouse blocks */}
      {warehouseBlocks.map((block, blockIdx) => {
        const group = warehouseGroupStatus.find(g => g.locId === String(block.warehouseId)) || null;
        const blockSubtotal = block.rows.reduce((s, r) => s + Number(r.subtotal || 0), 0);

        const recEntry = recsByLoc[String(block.warehouseId)] || { loading: false, recs: [] };
        const usedProductIds = new Set(block.rows.map(r => String(r.productId)).filter(Boolean));
        const suggestible = (recEntry.recs || []).filter(r => !usedProductIds.has(String(r.product_id)));
        const searchTerm = (lowStockSearch[block.id] || '').trim().toLowerCase();
        const priorityFilter = lowStockPriority[block.id] || '';
        const filteredLowStock = suggestible.filter(r => {
          if (priorityFilter && r.priority !== priorityFilter) return false;
          if (!searchTerm) return true;
          return [r.product?.product_name, r.product?.product_code]
            .some(value => String(value || '').toLowerCase().includes(searchTerm));
        });
        const criticalCount = filteredLowStock.filter(r => r.priority === 'CRITICAL').length;
        const lowStockPageCount = Math.max(1, Math.ceil(filteredLowStock.length / LOW_STOCK_PAGE_SIZE));
        const lowStockPage = Math.min(lowStockPages[block.id] || 1, lowStockPageCount);
        const visibleLowStock = filteredLowStock.slice(
          (lowStockPage - 1) * LOW_STOCK_PAGE_SIZE,
          lowStockPage * LOW_STOCK_PAGE_SIZE
        );
        const prItemPageCount = Math.max(1, Math.ceil(block.rows.length / PR_ITEMS_PAGE_SIZE));
        const prItemPage = Math.min(prItemPages[block.id] || 1, prItemPageCount);
        const visiblePrRows = block.rows.slice(
          (prItemPage - 1) * PR_ITEMS_PAGE_SIZE,
          prItemPage * PR_ITEMS_PAGE_SIZE
        );
        // Full per-warehouse reco lookup (incl. products already added) for the per-row hint.
        const recByProduct = new Map((recEntry.recs || []).map(r => [String(r.product_id), r]));
        // Open by default so PR creators immediately see the live low-stock list.
        // The set stores blocks the user explicitly collapsed.
        const panelOpen = !openRecPanels.has(block.id);

        return (
          <div key={block.id} className="po-line-items-card pr-warehouse-split" style={{ border: '1px solid #e2e8f0', borderRadius: 10, marginBottom: 16 }}>
            <div className="po-line-items-header">
              <div className="po-line-items-title" style={{ flex: 1 }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18">
                  <path d="M3 21h18" /><path d="M5 21V7l8-4v18" /><path d="M19 21V11l-6-4" />
                </svg>
                WAREHOUSE {blockIdx + 1}
                <select
                  className="po-select"
                  style={{ fontSize: 12, minWidth: 200, marginLeft: 8 }}
                  value={block.warehouseId}
                  onChange={e => setBlockWarehouse(block.id, e.target.value)}
                  disabled={loadingLocations}
                >
                  <option value="">{loadingLocations ? 'Loading…' : 'Select warehouse…'}</option>
                  {availableWarehousesForBlock(block.id).map(loc => (
                    <option key={loc.location_id ?? loc.id} value={String(loc.location_id ?? loc.id ?? '')}>
                      {loc.location_name || loc.name}
                    </option>
                  ))}
                </select>
              </div>
              {warehouseBlocks.length > 1 && (
                <button type="button" className="po-remove-btn" onClick={() => removeBlock(block.id)} title="Remove this warehouse block">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                    <polyline points="3 6 5 6 21 6" />
                    <path d="M19 6l-1 14H6L5 6" />
                    <path d="M10 11v6" /><path d="M14 11v6" />
                    <path d="M9 6V4h6v2" />
                  </svg>
                </button>
              )}
            </div>

            {block.warehouseId && (
              <div style={{ padding: '0 16px 16px' }}>
                {renderBudgetCard(group)}
              </div>
            )}

            {block.warehouseId && (
              <div className="pr-reco-panel">
                <button
                  type="button"
                  className="pr-reco-toggle"
                  onClick={() => setOpenRecPanels(prev => {
                    const next = new Set(prev);
                    if (next.has(block.id)) next.delete(block.id);
                    else next.add(block.id);
                    return next;
                  })}
                  aria-expanded={panelOpen}
                >
                  <span className="pr-reco-toggle-caret">{panelOpen ? '▾' : '▸'}</span>
                  {recEntry.loading
                    ? 'Checking low-stock items for this warehouse…'
                    : `${suggestible.length} low-stock item${suggestible.length === 1 ? '' : 's'} for this warehouse`}
                  {!recEntry.loading && criticalCount > 0 && (
                    <span className="pr-reco-count pr-reco-count--critical">
                      {criticalCount} critical
                    </span>
                  )}
                </button>

                {panelOpen && !recEntry.loading && suggestible.length === 0 && (
                  <div className="pr-reco-empty">No low-stock items for this warehouse right now.</div>
                )}

                {panelOpen && suggestible.length > 0 && (
                  <div className="pr-reco-list">
                    <div className="pr-reco-tools">
                      <input
                        type="search"
                        value={lowStockSearch[block.id] || ''}
                        placeholder="Filter product or code…"
                        onChange={e => {
                          setLowStockSearch(p => ({ ...p, [block.id]: e.target.value }));
                          setLowStockPages(p => ({ ...p, [block.id]: 1 }));
                        }}
                      />
                      <select
                        value={priorityFilter}
                        onChange={e => {
                          setLowStockPriority(p => ({ ...p, [block.id]: e.target.value }));
                          setLowStockPages(p => ({ ...p, [block.id]: 1 }));
                        }}
                      >
                        <option value="">All stock levels</option>
                        <option value="CRITICAL">Out of stock</option>
                        <option value="HIGH">Low stock</option>
                      </select>
                      <button
                        type="button"
                        disabled={visibleLowStock.length === 0}
                        onClick={() => visibleLowStock.forEach(item => addRecommendedItem(block.id, item))}
                      >
                        + Add All on Page
                      </button>
                    </div>
                    {visibleLowStock.map(r => (
                      <div key={r.recommendation_id} className="pr-reco-item">
                        <span className={RECO_PRIORITY_BADGE[r.priority] || 'ak-badge ak-badge--gray'}>
                          {r.priority}
                        </span>
                        <span className="pr-reco-item-name">
                          {r.product?.product_name || `#${r.product_id}`}
                          <span className="pr-reco-item-code">{r.product?.product_code}</span>
                        </span>
                        <span className="pr-reco-item-stat">stock {r.current_stock}</span>
                        <span className="pr-reco-item-stat">
                          {(() => {
                            const ppp = Number(r.pieces_per_package) || 0;
                            if (r.order_packages != null && ppp > 1) {
                              const pkgLabel  = r.package_unit || 'package';
                              const pieceAbbr = productById.get(String(r.product_id))?.unitAbbr || 'pcs';
                              return (
                                <>order <strong>{r.order_packages} {pkgLabel}{Number(r.order_packages) === 1 ? '' : 's'}</strong>
                                  {' '}({r.recommended_quantity} {pieceAbbr})</>
                              );
                            }
                            return <>order <strong>{r.recommended_quantity}</strong></>;
                          })()}
                        </span>
                        <span className="pr-reco-item-reasons">
                          {(r.reason_codes || []).map((c, i) => (
                            <span key={`${r.recommendation_id}-${c}-${i}`} className="ak-pill">{REASON_LABELS[c] || c}</span>
                          ))}
                        </span>
                        <button
                          type="button"
                          className="po-btn po-btn-add-product pr-reco-add"
                          onClick={() => addRecommendedItem(block.id, r)}
                        >
                          + Add
                        </button>
                      </div>
                    ))}
                    {lowStockPageCount > 1 && (
                      <div className="pr-reco-pagination">
                        <button
                          type="button"
                          disabled={lowStockPage === 1}
                          onClick={() => setLowStockPages(p => ({ ...p, [block.id]: lowStockPage - 1 }))}
                        >
                          Previous
                        </button>
                        <span>
                          Page <strong>{lowStockPage}</strong> of <strong>{lowStockPageCount}</strong>
                          {' '}· {filteredLowStock.length} products
                        </span>
                        <button
                          type="button"
                          disabled={lowStockPage === lowStockPageCount}
                          onClick={() => setLowStockPages(p => ({ ...p, [block.id]: lowStockPage + 1 }))}
                        >
                          Next
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

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
                  {visiblePrRows.map((r, visibleIdx) => {
                    const idx = ((prItemPage - 1) * PR_ITEMS_PAGE_SIZE) + visibleIdx;
                    return (
                    <React.Fragment key={r.id}>
                      <tr>
                        <td style={{ color: '#94a3b8', fontSize: 12 }}>{idx + 1}</td>
                        <td style={{ minWidth: 200 }}>
                          <ProductSearchSelect
                            products={products}
                            value={r.productId}
                            onChange={pid => handleProductSelect(block.id, r.id, pid)}
                            disabled={loadingProducts}
                            placeholder={loadingProducts ? 'Loading…' : 'Search product…'}
                          />
                        </td>
                        <td>
                          {r.productType ? (
                            <span className={`po-type-badge ${r.productType === 'installation_material' ? 'po-type-part' : 'po-type-appliance'}`}>
                              {r.productType.replace(/_/g, ' ').toUpperCase()}
                            </span>
                          ) : '—'}
                        </td>
                        <td style={{ fontSize: 12, color: '#64748b' }}>{productLabel(r)}</td>
                        <td>
                          <input
                            type="number" min="1"
                            className="po-qty-input"
                            value={r.qty}
                            onChange={e => updateRowField(block.id, r.id, 'qty', e.target.value)}
                          />
                          {r.productType === 'installation_material' && Number(r.piecesPerPackage) > 1 && (
                            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 2 }}>
                              × {r.piecesPerPackage} {productById.get(String(r.productId))?.unitAbbr || 'pcs'}/{r.packageUnit || 'package'}
                              {' '}= {(Number(r.qty) || 0) * Number(r.piecesPerPackage)} {productById.get(String(r.productId))?.unitAbbr || 'pcs'}
                            </div>
                          )}
                          {(() => {
                            if (!r.productId) return null;
                            // Prefer the analytics recommendation when the engine has one for
                            // this product at this warehouse; otherwise fall back to the
                            // product's configured recommended stock level (the analytics
                            // Stock Parameters tool raises/lowers that over time as demand
                            // data accumulates).
                            const rec  = recByProduct.get(String(r.productId));
                            const prod = productById.get(String(r.productId));
                            // Both sources are PIECES (recommended_stocks is pieces by
                            // convention); the qty field means PACKAGES for materials.
                            const suggestedQty = rec
                              ? (Number(rec.order_packages)
                                  || toOrderQty(Number(rec.recommended_quantity) || 0, prod))
                              : toOrderQty(Number(prod?.recommendedStocks) || 0, prod);
                            const suggestedUnit = prod?.type === 'installation_material'
                              && (Number(prod?.piecesPerPackage) || 0) > 1
                              ? ` ${prod.packageUnit || 'package'}${suggestedQty === 1 ? '' : 's'}`
                              : '';
                            if (suggestedQty <= 0 || Number(r.qty) === suggestedQty) return null;
                            return (
                              <button
                                type="button"
                                className="pr-qty-hint"
                                onClick={() => updateRowField(block.id, r.id, 'qty', suggestedQty)}
                                title={rec
                                  ? `Analytics-recommended order quantity at ${group?.locName || 'this warehouse'}`
                                  : 'Default recommended stock level for this product'}
                              >
                                Suggested: {suggestedQty}{suggestedUnit} → apply
                              </button>
                            );
                          })()}
                        </td>
                        <td>
                          <input
                            type="number" min="0" step="0.01"
                            className="po-qty-input"
                            style={{ width: 90 }}
                            value={r.estPrice}
                            onChange={e => updateRowField(block.id, r.id, 'estPrice', e.target.value)}
                          />
                        </td>
                        <td><strong>{fmtPeso(r.subtotal)}</strong></td>
                        <td>
                          <button
                            type="button" className="po-remove-btn"
                            onClick={() => removeRow(block.id, r.id)}
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
                      {r.reorderWarning && r.reorderWarning.length > 0 && !r.warningAcknowledged && (
                        <tr>
                          <td colSpan={8} style={{ padding: '0 8px 10px' }}>
                            <div className="pr-reorder-warning">
                              <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="15" height="15" style={{ flexShrink: 0 }}>
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                <line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
                              </svg>
                              <div style={{ display: 'inline' }}>
                                <strong>You already have open requests for {r.productName}:</strong>
                                {r.reorderWarning.map((w, wi) => (
                                  <div key={wi} style={{ marginTop: 4, fontSize: 12, paddingLeft: 4 }}>
                                    • <strong>{w.pr_number}</strong> ({w.status}):{' '}
                                    {w.quantity_requested} requested →{' '}
                                    {w.quantity_ordered} ordered in PO →{' '}
                                    <strong style={{ color: '#92400e' }}>{w.quantity_remaining} still remaining</strong>
                                    {w.needed_by_date && ` · needed by ${w.needed_by_date}`}
                                  </div>
                                ))}
                                {(() => {
                                  const totalRemaining = r.reorderWarning.reduce((t, w) => t + (w.quantity_remaining || 0), 0);
                                  return (
                                    <div style={{ marginTop: 6, fontSize: 12 }}>
                                      Adding more may cause overstock.{' '}
                                      <span style={{ fontStyle: 'italic' }}>Consider reducing your quantity by {totalRemaining}.</span>
                                    </div>
                                  );
                                })()}
                              </div>
                              <div className="pr-reorder-warning-actions">
                                <button type="button" className="pr-reorder-btn pr-reorder-btn--keep" onClick={() => acknowledgeWarning(block.id, r.id)}>
                                  Yes, add more
                                </button>
                                <button type="button" className="pr-reorder-btn pr-reorder-btn--remove" onClick={() => removeRow(block.id, r.id)}>
                                  Remove this line
                                </button>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                      {/* Acknowledged reminder */}
                      {r.reorderWarning && r.reorderWarning.length > 0 && r.warningAcknowledged && (
                        <tr>
                          <td colSpan={8} style={{ padding: '0 8px 8px' }}>
                            <div style={{ background: '#fef9c3', borderRadius: 6, padding: '5px 10px', fontSize: 11, color: '#713f12', display: 'flex', alignItems: 'flex-start', gap: 5 }}>
                              <svg viewBox="0 0 24 24" fill="none" stroke="#92400e" strokeWidth="2" width="12" height="12" style={{ flexShrink: 0, marginTop: 1 }}><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                              <span>Note: {r.reorderWarning.reduce((t, w) => t + (w.quantity_remaining || 0), 0)} unit(s) of {r.productName} already have open demand on other PRs.</span>
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

            {prItemPageCount > 1 && (
              <div className="pr-items-pagination">
                <button
                  type="button"
                  disabled={prItemPage <= 1}
                  onClick={() => setPrItemPages(p => ({ ...p, [block.id]: prItemPage - 1 }))}
                >
                  Previous
                </button>
                <span>
                  Items page <strong>{prItemPage}</strong> of <strong>{prItemPageCount}</strong>
                  {' '}({block.rows.length} items)
                </span>
                <button
                  type="button"
                  disabled={prItemPage >= prItemPageCount}
                  onClick={() => setPrItemPages(p => ({ ...p, [block.id]: prItemPage + 1 }))}
                >
                  Next
                </button>
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 16px 16px' }}>
              <button type="button" className="po-btn po-btn-add-product" onClick={() => addRow(block.id)}>
                + ADD PRODUCT
              </button>
              <span style={{ fontSize: 13, color: '#64748b' }}>
                BLOCK SUBTOTAL&nbsp; <strong style={{ fontSize: 15, color: '#1e293b' }}>{fmtPeso(blockSubtotal)}</strong>
              </span>
            </div>

            {group && !group.loading && !group.missing && group.over && (
              <div style={{ margin: '0 16px 14px', background: '#fffbeb', border: '1px solid #fbbf24', borderRadius: 8, padding: '10px 14px', fontSize: 13, color: '#92400e', display: 'flex', gap: 8, alignItems: 'flex-start' }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" strokeWidth="2" width="16" height="16" style={{ flexShrink: 0, marginTop: 1 }}>
                  <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                  <line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
                </svg>
                <span>
                  For {group.locName}, this block's items ({fmtPeso(group.subtotal)}) exceed the available budget ({fmtPeso(group.available)}) by{' '}
                  <strong>{fmtPeso(group.subtotal - group.available)}</strong>. Reduce the block or ask Admin to raise the budget.
                </span>
              </div>
            )}
          </div>
        );
      })}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
        <button
          type="button"
          className="po-btn po-btn-add-product"
          onClick={addBlock}
          disabled={allWarehousesUsed}
          title={allWarehousesUsed ? 'All warehouses are already used' : undefined}
        >
          + ADD ANOTHER WAREHOUSE
        </button>
        <div style={{ display: 'flex', alignItems: 'center', gap: 24 }}>
          <span style={{ fontSize: 13, color: '#64748b' }}>ESTIMATED TOTAL</span>
          <strong style={{ fontSize: 16, color: '#1e293b' }}>{fmtPeso(estimatedTotal)}</strong>
        </div>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 8 }}>
        <button
          type="button"
          className="po-btn po-btn-final-submission"
          onClick={handleSubmitPR}
          disabled={
            submitting ||
            !selectedSupplierId ||
            !hasAnyValidRow ||
            hasRowsWithoutWarehouse ||
            hasBlockingWarehouseGroup
          }
          title={
            !selectedSupplierId ? 'Select a supplier first'
            : !hasAnyValidRow ? 'Add at least one item with a selected product'
            : hasRowsWithoutWarehouse ? 'Select a destination warehouse for every warehouse block that has items'
            : hasBlockingWarehouseGroup ? 'One or more warehouses are over budget, unverified, or missing a budget'
            : undefined
          }
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
    if (loadingMyPRs) return <Loader label="Loading requests" />;
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
            <div
              key={pr.pr_id}
              className="po-status-card po-status-card--clickable"
              role="button"
              tabIndex={0}
              aria-label={`View details for ${pr.pr_number}`}
              onClick={(event) => {
                if (!event.target.closest('button, a, input, select, textarea')) setSelectedPR(pr);
              }}
              onKeyDown={(event) => {
                if ((event.key === 'Enter' || event.key === ' ') && event.target === event.currentTarget) {
                  event.preventDefault();
                  setSelectedPR(pr);
                }
              }}
            >
              <div className="po-status-card-body">
                <div className="po-status-card-left">
                  <div className="po-status-card-header-row">
                    <span className="po-status-card-number">{pr.pr_number}</span>
                    <span className={`po-status-badge po-status-badge--${cfg.color}`}>{cfg.label}</span>
                  </div>
                  <div className="po-status-card-meta">
                    <div className="po-status-meta-item">
                      <span className="po-status-meta-label">SUPPLIER</span>
                      <span className="po-status-meta-value">{pr.supplier?.supplier_name || '—'}</span>
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
                  <button type="button" className="po-status-view-btn" onClick={() => openPrHistory(pr)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                      <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
                    </svg>
                    HISTORY
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
    if (loadingPending) return <Loader label="Loading pending approvals" />;
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

          return (
            <div
              key={pr.pr_id}
              className="po-status-card po-status-card--clickable"
              role="button"
              tabIndex={0}
              aria-label={`Review ${pr.pr_number}`}
              onClick={(event) => {
                if (!event.target.closest('button, a, input, select, textarea')) setSelectedPR(pr);
              }}
              onKeyDown={(event) => {
                if ((event.key === 'Enter' || event.key === ' ') && event.target === event.currentTarget) {
                  event.preventDefault();
                  setSelectedPR(pr);
                }
              }}
            >
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
                      <span className="po-status-meta-label">SUPPLIER</span>
                      <span className="po-status-meta-value">{pr.supplier?.supplier_name || '—'}</span>
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
                          {groupDetailsByLocation(pr.details).map((grp, gi) => (
                            <React.Fragment key={gi}>
                              <tr>
                                <td colSpan={6} style={{ background: '#eef2f7', fontWeight: 700, fontSize: 11, letterSpacing: '.4px', color: '#334155', textTransform: 'uppercase' }}>
                                  {grp.locationName}
                                  <span style={{ float: 'right', fontWeight: 600, color: '#64748b' }}>{fmtPeso(grp.subtotal)}</span>
                                </td>
                              </tr>
                              {grp.rows.map((d, di) => (
                                <tr key={d.pr_detail_id ?? `${gi}-${di}`}>
                                  <td>
                                    <div>{d.product_name || d.product?.product_name || `Item ${di + 1}`}</div>
                                    {d.product?.applianceDetail?.model_code && (
                                      <small style={{ color: '#94a3b8' }}>{d.product.applianceDetail.model_code}</small>
                                    )}
                                  </td>
                                  <td>
                                    <span className={`po-type-badge ${(d.product_type || d.product?.product_type) === 'installation_material' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                                      {(d.product_type || d.product?.product_type) === 'installation_material' ? 'MATERIAL' : 'APPLIANCE'}
                                    </span>
                                  </td>
                                  <td style={{ color: '#64748b' }}>{detailLabel(d)}</td>
                                  <td>{d.quantity}</td>
                                  <td>{fmtPeso(d.estimated_price)}</td>
                                  <td>{fmtPeso(d.subtotal)}</td>
                                </tr>
                              ))}
                            </React.Fragment>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}

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
    const canAct      = pr.status_id === PR_STATUS.PENDING && canApprovePR;

    return (
      <Modal isOpen={!!selectedPR} onClose={() => setSelectedPR(null)} title="" hideHeader maxWidth={740}>
        <div className={`po-detail-modal${prPulse ? ' ak-focus-pulse' : ''}`}>
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
                {pr.supplier?.supplier_name || '—'} · {isoToMDY(pr.request_date)}
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
                <span className="po-detail-info-label">SUPPLIER</span>
                <span className="po-detail-info-value">{pr.supplier?.supplier_name || '—'}</span>
              </div>
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
                    {(() => {
                      let idx = 0;
                      return groupDetailsByLocation(details).map((grp, gi) => (
                        <React.Fragment key={gi}>
                          <tr>
                            <td colSpan={8} style={{ background: '#eef2f7', fontWeight: 700, fontSize: 11, letterSpacing: '.4px', color: '#334155', textTransform: 'uppercase' }}>
                              {grp.locationName}
                              <span style={{ float: 'right', fontWeight: 600, color: '#64748b' }}>{fmtPeso(grp.subtotal)}</span>
                            </td>
                          </tr>
                          {grp.rows.map((d, di) => {
                            idx += 1;
                            const remaining   = d.quantity_remaining ?? (d.quantity - (d.quantity_ordered_in_po ?? 0));
                            const orderedInPO = d.quantity_ordered_in_po ?? 0;
                            const hasOpen     = remaining > 0 && (pr.status_id === PR_STATUS.APPROVED || pr.status_id === PR_STATUS.PARTIAL);
                            const pType       = d.product_type || d.product?.product_type || '';
                            return (
                              <tr key={d.pr_detail_id ?? `${gi}-${di}`}
                                style={hasOpen ? { background: '#fffbeb' } : {}}>
                                <td className="po-detail-table-idx">{idx}</td>
                                <td>
                                  <div>{d.product_name || d.product?.product_name || `Item ${idx}`}</div>
                                  {d.product?.applianceDetail?.model_code && (
                                    <small style={{ color: '#94a3b8' }}>{d.product.applianceDetail.model_code}</small>
                                  )}
                                </td>
                                <td style={{ fontSize: 11 }}>
                                  <span className={`po-type-badge ${pType === 'installation_material' ? 'po-type-part' : 'po-type-appliance'}`} style={{ fontSize: 10 }}>
                                    {pType === 'installation_material' ? 'MATERIAL' : 'APPLIANCE'}
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
                        </React.Fragment>
                      ));
                    })()}
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
                <button type="button" className="pr-btn-reject" onClick={() => { setSelectedPR(null); handleReject(pr.pr_id); }}>
                  REJECT
                </button>
                <button type="button" className="pr-btn-approve" onClick={() => { setSelectedPR(null); handleApprove(pr.pr_id); }} disabled={isApproving}>
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
  // MAIN RENDER
  // ─────────────────────────────────────────────────────────────────────────
  return (
    <AdminLayout>
      <div className="ak-page po-recommendation-page">
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
          {canApprovePR && (
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
          {canReadReco && (
            <button
              type="button"
              className={`po-tab-btn${activeTab === 'stock-parameters' ? ' po-tab-btn--active' : ''}`}
              onClick={() => setActiveTab('stock-parameters')}
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
                <path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/>
              </svg>
              Stock Parameters
            </button>
          )}
          <button
            type="button"
            className={`po-tab-btn${activeTab === 'history' ? ' po-tab-btn--active' : ''}`}
            onClick={() => setActiveTab('history')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="15" height="15">
              <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
            </svg>
            History
          </button>
        </div>

        {/* Tab content */}
        {activeTab === 'create'       && canCreate  && renderCreateTab()}
        {activeTab === 'my-requests'               && renderMyRequestsTab()}
        {activeTab === 'approvals'    && canApprovePR && renderApprovalsTab()}

        {activeTab === 'history' && (
          <div className="ak-card" style={{ padding: 16 }}>
            {loadingHistoryPRs ? (
              <div className="rh-empty">Loading requests…</div>
            ) : historyPRs.length === 0 ? (
              <div className="rh-empty">No purchase requests found.</div>
            ) : (
              <div className="rh-record-list">
                {historyPRs.map((pr) => {
                  const cfg = getPRStatusConfig(pr.status_id);
                  return (
                    <div key={pr.pr_id} className="rh-record-row">
                      <div className="rh-record-main">
                        <strong>{pr.pr_number}</strong>
                        <span className={`po-detail-status-pill po-detail-status-pill--${cfg.color}`}>{cfg.label}</span>
                      </div>
                      <div className="rh-record-sub">
                        {pr.request_date ? String(pr.request_date).slice(0, 10) : ''} · {fmtPeso(pr.estimated_total)}
                      </div>
                      <button type="button" className="po-status-view-btn" onClick={() => openPrHistory(pr)}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                          <circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>
                        </svg>
                        VIEW HISTORY
                      </button>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        )}

        {activeTab === 'stock-parameters' && canReadReco && (
          <div className="ak-card">
            <div className="ak-toolbar">
              <div className="ak-muted" style={{ fontSize: 13 }}>
                {recoRunAt ? `Last computed: ${new Date(recoRunAt).toLocaleString()}` : 'No analytics run yet.'}
              </div>
              {canRecalcReco && (
                <div className="ak-toolbar--push">
                  <button className="ak-btn ak-btn--primary" onClick={handleRecalcReco} disabled={recalculating}>
                    {recalculating ? 'Recalculating…' : 'Recalculate now'}
                  </button>
                </div>
              )}
            </div>

            {!loadingSug && suggestions.length > 0 && (
              <div className="ak-toolbar" style={{ gap: 8, flexWrap: 'wrap' }}>
                <input
                  type="search"
                  className="ak-input"
                  style={{ minWidth: 220 }}
                  value={sugSearch}
                  onChange={e => setSugSearch(e.target.value)}
                  placeholder="Filter product or code…"
                />
                <select className="ak-input" value={sugWarehouse} onChange={e => setSugWarehouse(e.target.value)}>
                  <option value="">All warehouses</option>
                  {warehouseLocations.map(loc => (
                    <option key={loc.location_id ?? loc.id} value={String(loc.location_id ?? loc.id ?? '')}>
                      {loc.location_name || loc.name}
                    </option>
                  ))}
                </select>
                <select className="ak-input" value={sugCategory} onChange={e => setSugCategory(e.target.value)}>
                  <option value="">All categories</option>
                  {sugCategoryOptions.map(c => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
                <select className="ak-input" value={sugBrand} onChange={e => setSugBrand(e.target.value)}>
                  <option value="">All brands</option>
                  {sugBrandOptions.map(b => (
                    <option key={b.id} value={b.id}>{b.name}</option>
                  ))}
                </select>
                {(sugSearch || sugWarehouse || sugCategory || sugBrand) && (
                  <button
                    className="ak-btn ak-btn--sm ak-btn--ghost"
                    onClick={() => {
                      setSugSearch('');
                      setSugWarehouse('');
                      setSugCategory('');
                      setSugBrand('');
                    }}
                  >
                    Clear filters
                  </button>
                )}
                <span className="ak-muted" style={{ fontSize: 12, marginLeft: 'auto' }}>
                  {filteredSuggestions.length} of {suggestions.length}
                </span>
                {canApplyReco && (
                  <button
                    type="button"
                    className="ak-btn ak-btn--sm ak-btn--primary"
                    disabled={applyingAllSug || filteredSuggestions.length === 0 || busySuggestion !== null}
                    onClick={handleApplyAllSuggestions}
                    title="Apply every stock parameter suggestion currently matching the filters"
                  >
                    {applyingAllSug ? 'Applying…' : `Apply All Filtered (${filteredSuggestions.length})`}
                  </button>
                )}
              </div>
            )}

            {loadingSug ? (
              <Loader label="Loading suggestions" />
            ) : suggestions.length === 0 ? (
              <div className="ak-state">
                <p>No pending stock parameter suggestions. The analytics run raises one when a
                product's demand drifts away from its configured levels.</p>
              </div>
            ) : filteredSuggestions.length === 0 ? (
              <div className="ak-state">
                <p>No suggestions match the selected warehouse / category / brand filters.</p>
              </div>
            ) : (
              <div className="ak-table-wrap">
                <table className="ak-table">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th>Warehouses</th>
                      <th>Recommended stock <span className="ak-muted">(pieces)</span></th>
                      <th>Safety stock <span className="ak-muted">(pieces)</span></th>
                      <th>Max level <span className="ak-muted">(pieces)</span></th>
                      <th className="ak-actions">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredSuggestions.map((s) => (
                      <tr key={s.suggestion_id}>
                        <td>
                          <div>{s.product?.product_name || `#${s.product_id}`}</div>
                          <div className="ak-muted ak-mono">{s.product?.product_code}</div>
                        </td>
                        <td>
                          {(s.warehouses && s.warehouses.length > 0)
                            ? s.warehouses.map(w => w.location_name).join(', ')
                            : <span className="ak-muted">—</span>}
                        </td>
                        <td>{s.current_recommended} → <strong>{s.suggested_recommended}</strong></td>
                        <td>{s.current_safety ?? 0} → <strong>{s.suggested_safety ?? 0}</strong></td>
                        <td>{s.current_max ?? 0} → <strong>{s.suggested_max ?? 0}</strong></td>
                        <td className="ak-actions">
                          {canApplyReco ? (
                            <>
                              <button
                                className="ak-btn ak-btn--sm ak-btn--primary"
                                disabled={applyingAllSug || busySuggestion === s.suggestion_id}
                                onClick={() => handleApplySuggestion(s)}
                              >
                                Apply
                              </button>
                              <button
                                className="ak-btn ak-btn--sm ak-btn--ghost"
                                disabled={applyingAllSug || busySuggestion === s.suggestion_id}
                                onClick={() => handleDismissSuggestion(s)}
                              >
                                Dismiss
                              </button>
                            </>
                          ) : <span className="ak-muted">View only</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>

      {renderDetailModal()}

      <RecordHistoryModal
        isOpen={!!historyRecord}
        onClose={() => setHistoryRecord(null)}
        kind="Purchase Request"
        record={historyRecord}
        statusLabel={historyRecord ? getPRStatusConfig(historyRecord.status_id).label : ''}
        statusColor={historyRecord ? getPRStatusConfig(historyRecord.status_id).color : 'draft'}
        milestones={prHistory}
        loading={prHistoryLoading}
      />
    </AdminLayout>
  );
};

export default PurchaseRequestPage;
