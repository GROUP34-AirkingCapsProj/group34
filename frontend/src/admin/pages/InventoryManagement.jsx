import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Modal from '../components/Modal';
import { useLocation } from 'react-router-dom';
import { inventoryAPI, locationsAPI, categoriesAPI, batchAPI, dashboardAPI, scanEventsAPI, transactionTypesAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/inventory_management.css';
import { useFocusTarget } from '../hooks/useFocusTarget';
import { useFocusPulse } from '../hooks/useFocusPulse';
import { findById } from '../utils/findById';
import { toast } from '../utils/toast';
import { getPageBatch } from '../utils/pageBatch';

/* ── SVG trend chart ─────────────────────────────────────────────────── */
const TrendChart = ({ data, loading }) => {
  const W = 480, H = 200, pL = 36, pR = 16, pT = 12, pB = 28;
  const cW = W - pL - pR, cH = H - pT - pB;

  const days = data?.labels  ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const buys = data?.inbound  ?? new Array(days.length).fill(0);
  const sals = data?.outbound ?? new Array(days.length).fill(0);
  const maxV = Math.max(...sals, ...buys, 1) * 1.15;

  const px = (i) => pL + i * (cW / (days.length - 1));
  const py = (v) => pT + cH - (v / maxV) * cH;
  const baseY = pT + cH;

  const curve = (pts) => {
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 1; i < pts.length; i++) {
      const cx = (pts[i - 1][0] + pts[i][0]) / 2;
      d += ` C${cx},${pts[i - 1][1]} ${cx},${pts[i][1]} ${pts[i][0]},${pts[i][1]}`;
    }
    return d;
  };

  const area = (pts) => `${curve(pts)} L${pts[pts.length - 1][0]},${baseY} L${pts[0][0]},${baseY} Z`;

  const buyPts = buys.map((v, i) => [px(i), py(v)]);
  const salPts = sals.map((v, i) => [px(i), py(v)]);
  const yTicks = [0, 25, 50, 75, 100].map((p) => ({
    y: py(maxV * p / 100),
    label: Math.round(maxV * p / 100),
  }));

  return (
    <div className="sm-chart-wrap">
      {loading && (
        <div style={{ height: H, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#9ca3af', fontSize: 13 }}>
          Loading chart data…
        </div>
      )}
      <svg width="100%" viewBox={`0 0 ${W} ${H}`} style={{ overflow: 'visible', display: loading ? 'none' : undefined }}>
        <defs>
          <linearGradient id="smSG" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#dc2626" stopOpacity="0.18" />
            <stop offset="100%" stopColor="#dc2626" stopOpacity="0.02" />
          </linearGradient>
          <linearGradient id="smBG" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#f59e0b" stopOpacity="0.15" />
            <stop offset="100%" stopColor="#f59e0b" stopOpacity="0.02" />
          </linearGradient>
        </defs>

        {yTicks.map((t, i) => (
          <g key={i}>
            <line x1={pL} y1={t.y} x2={W - pR} y2={t.y} stroke="#f3f4f6" strokeWidth="1" strokeDasharray="4 3" />
            <text x={pL - 5} y={t.y + 4} textAnchor="end" fontSize="9" fill="#d1d5db">{t.label}</text>
          </g>
        ))}

        <path d={area(salPts)} fill="url(#smSG)" />
        <path d={area(buyPts)} fill="url(#smBG)" />
        <path d={curve(salPts)} fill="none" stroke="#dc2626" strokeWidth="2.5" strokeLinecap="round" />
        <path d={curve(buyPts)} fill="none" stroke="#f59e0b" strokeWidth="2" strokeLinecap="round" />

        {days.map((d, i) => (
          <text key={i} x={px(i)} y={H - 4} textAnchor="middle" fontSize="10" fill="#9ca3af">{d}</text>
        ))}
      </svg>

      <div className="sm-chart-legend">
        <span className="sm-legend-item">
          <span className="sm-legend-dot" style={{ background: '#f59e0b' }} />purchase
        </span>
        <span className="sm-legend-item">
          <span className="sm-legend-dot" style={{ background: '#dc2626' }} />sales
        </span>
      </div>
    </div>
  );
};

/* ── Main component ──────────────────────────────────────────────────── */

const InventoryManagement = () => {
  const routerLocation = useLocation();
  const initialTab = new URLSearchParams(routerLocation.search).get('tab') || 'stock-movement';
  const [activeTab, setActiveTab] = useState(initialTab);

  // Inventory state
  const [inventory, setInventory] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [viewingItem, setViewingItem] = useState(null);
  const [historyData, setHistoryData] = useState(null);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyError, setHistoryError] = useState(null);
  const [detailTab, setDetailTab] = useState('overview');
  const focusInvId = useFocusTarget('inv');
  const focusHandledRef = useRef(false);
  const [invPulse, fireInvPulse] = useFocusPulse();
  const [serialModalBatch, setSerialModalBatch] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [stockLocationFilter, setStockLocationFilter] = useState('');
  const [stockCategoryFilter, setStockCategoryFilter] = useState('');
  const [stockStatusFilter, setStockStatusFilter] = useState('');
  const [smSearch, setSmSearch] = useState('');
  const [trendData, setTrendData] = useState(null);
  const [trendLoading, setTrendLoading] = useState(false);
  const rowsPerPage = 10;
  const debounceRef = useRef(null);

  // Activity log state
  const [activityLog, setActivityLog] = useState([]);
  const [activityLoading, setActivityLoading] = useState(false);
  const [activitySearch, setActivitySearch] = useState('');
  const [activityTypeFilter, setActivityTypeFilter] = useState('');
  const [transactionTypes, setTransactionTypes] = useState([]);

  useEffect(() => {
    const fetchLookups = async () => {
      try {
        const batchRes = await batchAPI.get({ include: ['locations', 'categories'] });
        const batchData = batchRes?.data || {};
        setWarehouses(Array.isArray(batchData.locations?.data) ? batchData.locations.data : []);
        setCategories(Array.isArray(batchData.categories?.data) ? batchData.categories.data : []);
      } catch (err) {
        console.error('Batch fetch failed, falling back:', err);
        try {
          const [whRes, catRes] = await Promise.all([
            locationsAPI.getAll({ per_page: 200 }),
            categoriesAPI.getAll(),
          ]);
          setWarehouses(Array.isArray(whRes.data) ? whRes.data : []);
          setCategories(Array.isArray(catRes?.data) ? catRes.data : []);
        } catch (e) { console.error('Fallback fetch failed:', e); }
      }
      try {
        const ttRes = await transactionTypesAPI.getAll();
        setTransactionTypes(Array.isArray(ttRes?.data) ? ttRes.data : []);
      } catch (e) { console.error('Transaction types fetch failed:', e); }
    };
    fetchLookups();
  }, []);

  useEffect(() => {
    const fetchTrends = async () => {
      setTrendLoading(true);
      try {
        const res = await dashboardAPI.getStockMovementTrends();
        setTrendData(res?.data ?? null);
      } catch (err) {
        console.error('Stock movement trends fetch failed:', err);
      } finally {
        setTrendLoading(false);
      }
    };
    fetchTrends();
  }, []);

  const fetchInventory = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const params = { page, per_page: rowsPerPage };
      if (searchTerm) params.search = searchTerm;
      if (stockLocationFilter) params.location_id = stockLocationFilter;
      if (stockCategoryFilter) params.category_id = stockCategoryFilter;
      if (stockStatusFilter) params.stock_status = stockStatusFilter;
      const res = await inventoryAPI.getAll(params);
      setInventory(Array.isArray(res.data) ? res.data : []);
      setPagination(res.pagination || { total: 0, last_page: 1 });
      setError(null);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, stockLocationFilter, stockCategoryFilter, stockStatusFilter, rowsPerPage]);

  const loadInventoryHistory = useCallback(async (inventoryId) => {
    setHistoryLoading(true);
    setHistoryError(null);
    try {
      const data = await inventoryAPI.getHistory(inventoryId);
      if (data.success) {
        setHistoryData(data.data);
      } else {
        setHistoryError('Could not load stock history.');
      }
    } catch {
      setHistoryError('Network error loading stock history.');
    } finally {
      setHistoryLoading(false);
    }
  }, []);

  const [poGroups, setPoGroups] = useState([]);
  const [poGroupsLoading, setPoGroupsLoading] = useState(false);
  const [poGroupsError, setPoGroupsError] = useState(null);
  const [poSearch, setPoSearch] = useState('');
  const [inventorySubTab, setInventorySubTab] = useState('po-console');
  const [editingLocKey, setEditingLocKey] = useState(null);
  const [editingLocValue, setEditingLocValue] = useState('');
  const [savingLoc, setSavingLoc] = useState(false);
  const [poGroupDetail, setPoGroupDetail] = useState(null);
  const [poGroupDetailLoading, setPoGroupDetailLoading] = useState(false);
  const [poDetailTab, setPoDetailTab] = useState('overview');

  const fetchPoGroups = useCallback(async () => {
    setPoGroupsLoading(true);
    setPoGroupsError(null);
    try {
      const res = await inventoryAPI.getPoGroups();
      setPoGroups(res?.data ?? []);
    } catch (err) {
      setPoGroupsError(err?.message || 'Failed to load PO inventory groups.');
    } finally {
      setPoGroupsLoading(false);
    }
  }, []);

  const openPoGroupDetail = async (group) => {
    setPoGroupDetail({ loading: true, group });
    setPoDetailTab('overview');
    setPoGroupDetailLoading(true);
    try {
      const res = await inventoryAPI.getPoGroupDetail(group.po_id, group.warehouse_id);
      setPoGroupDetail({ group, data: res?.data ?? null });
    } catch (err) {
      setPoGroupDetail({ group, error: err?.message || 'Failed to load PO details.' });
    } finally {
      setPoGroupDetailLoading(false);
    }
  };

  const startEditingLoc = (group) => {
    setEditingLocKey(group.group_key);
    setEditingLocValue(group.location_label || '');
  };

  const saveLoc = async (group) => {
    setSavingLoc(true);
    try {
      await inventoryAPI.updatePoGroupLocation(group.po_id, group.warehouse_id, editingLocValue.trim() || null);
      setEditingLocKey(null);
      await fetchPoGroups();
    } catch (err) {
      toast.error(err?.message || 'Failed to update location.');
    } finally {
      setSavingLoc(false);
    }
  };

  useEffect(() => { fetchInventory(currentPage); }, [currentPage, fetchInventory]);

  // Re-fetch when a barcode scan transaction is confirmed anywhere in the app.
  useEffect(() => {
    const onScanApplied = () => {
      fetchInventory(currentPage);
      // The PO console's quantities come from a snapshot fetched only when the
      // sub-tab becomes active, so re-fetch it too when it's the one on screen.
      if (activeTab === 'inventory' && inventorySubTab === 'po-console') {
        fetchPoGroups();
      }
    };
    window.addEventListener('inventory-scan-applied', onScanApplied);
    return () => window.removeEventListener('inventory-scan-applied', onScanApplied);
  }, [fetchInventory, currentPage, activeTab, inventorySubTab, fetchPoGroups]);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setCurrentPage(1), 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm, stockLocationFilter, stockCategoryFilter, stockStatusFilter]);

  useEffect(() => {
    if (!focusInvId || focusHandledRef.current) return;
    if (loading) return;
    focusHandledRef.current = true;
    const item = findById(inventory, ['inventory_id', 'id'], focusInvId);
    if (item) {
      setViewingItem(item);
      setHistoryData(null);
      setDetailTab('overview');
      loadInventoryHistory(item.inventory_id);
      fireInvPulse();
    } else {
      toast.error('That inventory item is not available.');
    }
  }, [focusInvId, loading, inventory, loadInventoryHistory, fireInvPulse]);

  useEffect(() => {
    if (activeTab !== 'activity') return;
    let cancelled = false;
    const fetchActivity = async () => {
      setActivityLoading(true);
      try {
        const res = await scanEventsAPI.getAll({ per_page: 200 });
        if (cancelled) return;
        setActivityLog(Array.isArray(res?.data) ? res.data : []);
      } catch (err) {
        console.error('Activity log fetch failed:', err);
      } finally {
        if (!cancelled) setActivityLoading(false);
      }
    };
    fetchActivity();
    return () => { cancelled = true; };
  }, [activeTab]);

  useEffect(() => {
    if (!(activeTab === 'inventory' && inventorySubTab === 'po-console')) return;
    fetchPoGroups();
  }, [activeTab, inventorySubTab, fetchPoGroups]);

  /* ── helpers ─── */
  const getItemName    = (item) => item.product?.product_name || item.item?.name || item.item_name || 'N/A';
  const getItemCode    = (item) => item.product?.product_code || item.item?.code || item.sku || '';
  // Appliance model code, falling back to barcode for consumables/bulk items.
  const getModelCode   = (item) => item.product?.applianceDetail?.model_code || item.product?.model_code || item.model_code || item.product?.barcode || '';
  const getItemQty     = (item) => Number(item.quantity_on_hand ?? item.quantity ?? 0);
  const getItemReorder = (item) => Number(item.product?.recommended_stocks || item.reorder_level || 5);
  const getItemSafety  = (item) => Number(item.product?.safety_stock) || Math.round(getItemReorder(item) * 0.25) || 1;
  const getItemMax     = (item) => Number(item.product?.max_stock_level) || getItemReorder(item) * 4 || 100;
  const getBranchName  = (item) => item.location?.location_name || item.branch?.name || item.warehouse_location || 'N/A';
  const getItemCat     = (item) =>
    item.product?.category?.category_name ||
    item.category_name ||
    item.category?.name ||
    'General';

  const getItemStatus = (item) => {
    const qty = getItemQty(item), reorder = getItemReorder(item);
    if (qty <= 0)              return { label: 'OUT OF STOCK',   className: 'inv-badge-outofstock' };
    if (qty <= reorder * 0.5)  return { label: 'CRITICAL LOW',   className: 'inv-badge-critical' };
    if (qty <= reorder)        return { label: 'REORDER POINT',  className: 'inv-badge-reorder' };
    if (qty >= getItemMax(item)) return { label: 'OVERSTOCK RISK', className: 'inv-badge-overstock' };
    return { label: 'OPTIMAL LEVEL', className: 'inv-badge-optimal' };
  };

  /* ── stock-movement derived data ─── */
  const currentStock = useMemo(() => inventory.reduce((s, i) => s + getItemQty(i), 0), [inventory]);

  // Use real 30-day totals from API when available; fall back to inventory-derived estimates
  const smTotalIn  = trendData?.total_in  ?? currentStock;
  const smTotalOut = trendData?.total_out ?? Math.round(currentStock * 0.74);

  // Period-over-period percentage changes (null when no prior-period data)
  const smInPct = useMemo(() => {
    if (!trendData || trendData.prev_total_in === 0) return null;
    return Math.round((trendData.total_in - trendData.prev_total_in) / trendData.prev_total_in * 100);
  }, [trendData]);
  const smOutPct = useMemo(() => {
    if (!trendData || trendData.prev_total_out === 0) return null;
    return Math.round((trendData.total_out - trendData.prev_total_out) / trendData.prev_total_out * 100);
  }, [trendData]);

  const smTurnover = useMemo(() => {
    const avgStock = currentStock > 0 ? currentStock : 1;
    return smTotalOut > 0 ? (smTotalOut / avgStock).toFixed(1) : '0.0';
  }, [smTotalOut, currentStock]);
  const smDays = useMemo(() =>
    parseFloat(smTurnover) > 0 ? Math.round(30 / parseFloat(smTurnover)) : 0,
    [smTurnover]);

  const smFastCount = useMemo(
    () => inventory.filter((i) => getItemQty(i) >= getItemReorder(i) * 3).length,
    [inventory]);
  const smSlowCount = useMemo(
    () => inventory.filter((i) => { const q = getItemQty(i); return q > 0 && q < getItemReorder(i); }).length,
    [inventory]);

  const categoryPerf = useMemo(() => {
    const map = {};
    inventory.forEach((item) => {
      const cat = getItemCat(item);
      if (!map[cat]) map[cat] = { total: 0, reorder: 0 };
      map[cat].total   += getItemQty(item);
      map[cat].reorder += Math.max(getItemReorder(item), 1);
    });
    const cats = Object.entries(map)
      .map(([name, d]) => ({ name, rate: parseFloat((d.total / d.reorder).toFixed(1)) }))
      .sort((a, b) => b.rate - a.rate)
      .slice(0, 4);
    const maxR = cats.length > 0 ? cats[0].rate : 1;
    return cats.map((c) => ({ ...c, pct: maxR > 0 ? (c.rate / maxR) * 100 : 0 }));
  }, [inventory]);

  const top20 = useMemo(() => {
    const term = smSearch.toLowerCase();
    const filtered = term
      ? inventory.filter(
          (i) =>
            getItemName(i).toLowerCase().includes(term) ||
            getItemCode(i).toLowerCase().includes(term)
        )
      : inventory;
    return [...filtered]
      .sort((a, b) => getItemQty(b) - getItemQty(a))
      .slice(0, 20)
      .map((item, idx) => {
        const qty     = getItemQty(item);
        const reorder = getItemReorder(item);
        const inbound  = Math.round(qty * 0.6);
        const outbound = Math.round(qty * 0.85);
        const turnover = reorder > 0 ? parseFloat((outbound / reorder).toFixed(1)) : 0;
        return { item, rank: idx + 1, inbound, outbound, turnover, cat: getItemCat(item) };
      });
  }, [inventory, smSearch]);

  /* ── inventory-tab counts ─── */
  const totalItems    = pagination.total || 0;
  const totalPages    = pagination.last_page || 1;
  const criticalCount = inventory.filter((i) => getItemQty(i) <= getItemReorder(i) * 0.5 && getItemQty(i) > 0).length;
  const reorderCount  = inventory.filter((i) => {
    const qty = getItemQty(i), reorder = getItemReorder(i);
    return qty > reorder * 0.5 && qty <= reorder;
  }).length;
  const overstockCount = inventory.filter((i) => getItemQty(i) >= getItemMax(i)).length;
  const stockOutHistory = (historyData?.movement_history || []).filter((m) => m.type === 'Stock Out');
  const transferHistory = (historyData?.movement_history || []).filter((m) => m.type === 'Transfer');

  return (
    <AdminLayout>
      <div className="ak-page inv-page">

        {/* ── Page header ── */}
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory · Stock Control</p>
              <h1 className="ak-title">Inventory Monitoring</h1>
              <p className="ak-sub">
                {activeTab === 'stock-movement'
                  ? 'Comprehensive stock in/out analysis and inventory velocity.'
                  : activeTab === 'activity'
                  ? 'Complete audit trail of all stock movements — receivings, issuances, transfers, and adjustments.'
                  : 'Real-time inventory levels and safety stock tracking across all Mindanao locations.'}
              </p>
            </div>
          </div>
        </header>

        {/* ── Tab navigation ── */}
        <div className="ak-tabs inv-tabs">
          <button
            className={`ak-tab ${activeTab === 'stock-movement' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('stock-movement')}
          >
            Stock Movement &amp; Turnover
          </button>
          <button
            className={`ak-tab ${activeTab === 'inventory' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('inventory')}
          >
            Inventory
          </button>
          <button
            className={`ak-tab ${activeTab === 'activity' ? 'is-active' : ''}`}
            onClick={() => setActiveTab('activity')}
          >
            Activity Log
          </button>
        </div>

        {/* ── Inventory sub-tabs ── */}
        {activeTab === 'inventory' && (
          <div className="ak-tabs inv-subtabs">
            <button
              className={`ak-tab ${inventorySubTab === 'po-console' ? 'is-active' : ''}`}
              onClick={() => setInventorySubTab('po-console')}
            >
              Inventory Batch
            </button>
            <button
              className={`ak-tab ${inventorySubTab === 'stock-level' ? 'is-active' : ''}`}
              onClick={() => setInventorySubTab('stock-level')}
            >
              Stock-Level Monitoring
            </button>
          </div>
        )}

        {/* ════════════════════════════════════════════════
            STOCK MOVEMENT & TURNOVER TAB
        ════════════════════════════════════════════════ */}
        {activeTab === 'stock-movement' && (
          <>
            {/* Metric cards */}
            <div className="sm-stats-row">
              <div className="sm-stat-card">
                <span className="sm-stat-label">TOTAL STOCK IN <span style={{fontSize:10,color:'#9ca3af',fontWeight:400}}>(30d)</span></span>
                <div className="sm-stat-value-row">
                  <span className="sm-stat-value">{smTotalIn.toLocaleString()}</span>
                  {smInPct !== null && (
                    <span className={`sm-badge ${smInPct >= 0 ? 'sm-badge-green' : 'sm-badge-red'}`}>
                      {smInPct >= 0 ? '↑' : '↓'} {Math.abs(smInPct)}%
                    </span>
                  )}
                  {false && historyData.movement_history?.length > 0 && (
                    <>
                      <div className="invd-section-title" style={{ marginTop: 24 }}>Movement Timeline</div>
                      <div className="invd-table-wrap">
                        <table className="invd-table">
                          <thead>
                            <tr>
                              <th>Movement</th>
                              <th>Quantity</th>
                              <th>Date &amp; Time</th>
                              <th>Reference</th>
                              <th>Serial / Barcode</th>
                              <th>From</th>
                              <th>To</th>
                              <th>Performed By</th>
                            </tr>
                          </thead>
                          <tbody>
                            {historyData.movement_history.map((movement, index) => (
                              <tr key={`${movement.type}-${movement.reference || index}-${index}`}>
                                <td>{movement.type}</td>
                                <td className="invd-mono">{movement.quantity}</td>
                                <td>{movement.date_time ? new Date(movement.date_time).toLocaleString('en-PH', {
                                  year: 'numeric', month: 'short', day: 'numeric',
                                  hour: '2-digit', minute: '2-digit', second: '2-digit',
                                }) : '—'}</td>
                                <td className="invd-mono">{movement.reference || '—'}</td>
                                <td className="invd-mono">{movement.serial_number || '—'}</td>
                                <td>{movement.from_location || '—'}</td>
                                <td>{movement.to_location || '—'}</td>
                                <td>{movement.performed_by || '—'}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </>
                  )}
                </div>
              </div>
              <div className="sm-stat-card">
                <span className="sm-stat-label">TOTAL STOCK OUT <span style={{fontSize:10,color:'#9ca3af',fontWeight:400}}>(30d)</span></span>
                <div className="sm-stat-value-row">
                  <span className="sm-stat-value">{smTotalOut.toLocaleString()}</span>
                  {smOutPct !== null && (
                    <span className={`sm-badge ${smOutPct >= 0 ? 'sm-badge-green' : 'sm-badge-red'}`}>
                      {smOutPct >= 0 ? '↑' : '↓'} {Math.abs(smOutPct)}%
                    </span>
                  )}
                </div>
              </div>
              <div className="sm-stat-card">
                <span className="sm-stat-label">INVENTORY TURNOVER</span>
                <div className="sm-stat-value-row">
                  <span className="sm-stat-value">{smTurnover}x</span>
                </div>
              </div>
              <div className="sm-stat-card">
                <span className="sm-stat-label">DAYS IN INVENTORY</span>
                <div className="sm-stat-value-row">
                  <span className="sm-stat-value">{smDays} Days</span>
                </div>
              </div>
            </div>

            {/* Search + filters */}
            <div className="sm-filter-row">
              <div className="sm-search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" />
                </svg>
                <input
                  type="text"
                  placeholder="Search..."
                  value={smSearch}
                  onChange={(e) => setSmSearch(e.target.value)}
                />
              </div>
              <select className="sm-select"><option value="">All Type</option><option>Purchase</option><option>Sale</option><option>Transfer</option></select>
              <select className="sm-select">
                <option value="">All Category</option>
                {categories.map((c) => <option key={c.id}>{c.category_name || c.name}</option>)}
              </select>
              <select className="sm-select"><option value="">All Movement</option><option>Fast Moving</option><option>Slow Moving</option></select>
            </div>

            {/* Charts grid */}
            <div className="sm-charts-grid">
              {/* Trend chart */}
              <div className="sm-card">
                <h3 className="sm-card-title">Stock Movement Trend</h3>
                <p className="sm-card-sub">Daily inbound vs outbound unit volume</p>
                <TrendChart data={trendData} loading={trendLoading} />
              </div>

              {/* Right column */}
              <div className="sm-right-col">
                {/* Category performance */}
                <div className="sm-card">
                  <h3 className="sm-card-title">Category Performance</h3>
                  <p className="sm-card-sub">Turnover velocity by product type</p>
                  <div className="sm-cat-list">
                    {categoryPerf.length > 0 ? categoryPerf.map((c, i) => (
                      <div key={i} className="sm-cat-row">
                        <div className="sm-cat-header">
                          <span className="sm-cat-name">{c.name}</span>
                          <span className="sm-cat-rate">{c.rate}x</span>
                        </div>
                        <div className="sm-cat-track">
                          <div className="sm-cat-fill" style={{ width: `${c.pct}%` }} />
                        </div>
                      </div>
                    )) : (
                      <p style={{ color: '#9ca3af', fontSize: 12, textAlign: 'center', padding: '12px 0' }}>
                        No category data available
                      </p>
                    )}
                  </div>
                </div>

                {/* Fast / slow moving */}
                <div className="sm-moving-grid">
                  <div className="sm-fast-card">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2">
                      <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                    </svg>
                    <div className="sm-moving-label sm-fast-label">FAST MOVING</div>
                    <div className="sm-moving-count sm-fast-count">{smFastCount} Items</div>
                  </div>
                  <div className="sm-slow-card">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ea580c" strokeWidth="2">
                      <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                    </svg>
                    <div className="sm-moving-label sm-slow-label">SLOW MOVING</div>
                    <div className="sm-moving-count sm-slow-count">{smSlowCount} Items</div>
                  </div>
                </div>
              </div>
            </div>

            {/* Top 20 Moving Products */}
            <div className="sm-card sm-products-card">
              <div className="sm-products-header">
                <h3 className="sm-card-title">Top 20 Moving Products</h3>
                <p className="sm-card-sub">Ranked by stock movement frequency and turnover ratio</p>
              </div>

              {loading && <Loader />}

              <div className="inv-table-wrapper">
                <table className="inv-table">
                  <thead>
                    <tr>
                      <th>RANK &amp; PRODUCT</th>
                      <th>CATEGORY</th>
                      <th>INBOUND (30D)</th>
                      <th>OUTBOUND (30D)</th>
                      <th>TURNOVER</th>
                      <th>STATUS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {top20.length === 0 ? (
                      <tr>
                        <td colSpan="6" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                          {loading ? 'Loading...' : 'No products found'}
                        </td>
                      </tr>
                    ) : top20.map(({ item, rank, inbound, outbound, turnover, cat }) => (
                      <tr key={item.inventory_id}>
                        <td>
                          <div className="sm-rank-cell">
                            <span className="sm-rank">#{String(rank).padStart(2, '0')}</span>
                            <div>
                              <div className="inv-product-name">{getItemName(item)}</div>
                              {getModelCode(item) && <div className="inv-product-code">{getModelCode(item)}</div>}
                            </div>
                          </div>
                        </td>
                        <td><span className="sm-cat-badge">{cat.toUpperCase()}</span></td>
                        <td className="sm-inbound">+{inbound}</td>
                        <td className="sm-outbound">-{outbound}</td>
                        <td className="sm-turnover">{turnover}x</td>
                        <td>
                          <button className="sm-row-action" aria-label="View details">›</button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </>
        )}

        {/* ════════════════════════════════════════════════
            INVENTORY TAB
        ════════════════════════════════════════════════ */}
        {activeTab === 'inventory' && inventorySubTab === 'stock-level' && (
          <>
            {/* Search + Export */}
            <div className="inv-search-row">
              <div className="inv-search-input">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
                  <circle cx="11" cy="11" r="8"></circle>
                  <path d="m21 21-4.35-4.35"></path>
                </svg>
                <input
                  type="text"
                  placeholder="Search by name, SKU, or barcode..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                />
              </div>
              <select className="inv-filter-select" value={stockLocationFilter} onChange={(e) => setStockLocationFilter(e.target.value)}>
                <option value="">All Locations</option>
                {warehouses.map((location) => (
                  <option key={location.location_id} value={location.location_id}>
                    {location.location_name}
                  </option>
                ))}
              </select>
              <select className="inv-filter-select" value={stockCategoryFilter} onChange={(e) => setStockCategoryFilter(e.target.value)}>
                <option value="">All Categories</option>
                {categories.map((category) => (
                  <option key={category.category_id} value={category.category_id}>
                    {category.category_name}
                  </option>
                ))}
              </select>
              <select className="inv-filter-select" value={stockStatusFilter} onChange={(e) => setStockStatusFilter(e.target.value)}>
                <option value="">All Statuses</option>
                <option value="out">Out of Stock</option>
                <option value="critical">Critical Low</option>
                <option value="reorder">Reorder Point</option>
                <option value="optimal">Optimal Level</option>
                <option value="overstock">Overstock Risk</option>
              </select>
              {(stockLocationFilter || stockCategoryFilter || stockStatusFilter) && (
                <button type="button" className="inv-filter-clear" onClick={() => {
                  setStockLocationFilter('');
                  setStockCategoryFilter('');
                  setStockStatusFilter('');
                }}>Clear</button>
              )}
              <button className="inv-btn-export">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                  <polyline points="7 10 12 15 17 10"></polyline>
                  <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                EXPORT
              </button>
            </div>

            {/* Stats Cards */}
            <div className="inv-stats-row">
              <div className="inv-stat-card">
                <div className="inv-stat-icon inv-stat-icon-green">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" strokeWidth="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                  </svg>
                </div>
                <div className="inv-stat-info">
                  <span className="inv-stat-label">TOTAL SKUS</span>
                  <span className="inv-stat-number">{totalItems}</span>
                </div>
              </div>
              <div className="inv-stat-card">
                <div className="inv-stat-icon inv-stat-icon-red">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                  </svg>
                </div>
                <div className="inv-stat-info">
                  <span className="inv-stat-label">CRITICAL STOCK</span>
                  <span className="inv-stat-number inv-stat-red">{criticalCount}</span>
                </div>
              </div>
              <div className="inv-stat-card">
                <div className="inv-stat-icon inv-stat-icon-orange">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" strokeWidth="2">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                  </svg>
                </div>
                <div className="inv-stat-info">
                  <span className="inv-stat-label">REORDER LEVEL</span>
                  <span className="inv-stat-number">{reorderCount}</span>
                </div>
              </div>
              <div className="inv-stat-card">
                <div className="inv-stat-icon inv-stat-icon-emerald">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" strokeWidth="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                  </svg>
                </div>
                <div className="inv-stat-info">
                  <span className="inv-stat-label">OVERSTOCK RISK</span>
                  <span className="inv-stat-number">{overstockCount}</span>
                </div>
              </div>
            </div>

            {loading && <Loader label="Loading inventory" />}
            {error && <div className="inv-error">Error: {error}</div>}

            {/* Inventory Console Table */}
            <div className="inv-table-card">
              <div className="inv-table-top">
                <div>
                  <h3 className="inv-table-title">Stock-Level Monitoring</h3>
                  <p className="inv-table-subtitle">Monitoring {totalItems} SKUs across branches</p>
                </div>
              </div>

              <div className="inv-table-wrapper">
                <table className="inv-table">
                  <thead>
                    <tr>
                      <th>PRODUCT &amp; BRANCH</th>
                      <th>CURRENT</th>
                      <th>PACKAGES</th>
                      <th>SAFETY</th>
                      <th>REORDER</th>
                      <th>MAXIMUM</th>
                      <th>STATUS</th>
                      <th>ACTIONS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {inventory.length === 0 ? (
                      <tr>
                        <td colSpan="8" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                          {loading ? 'Loading...' : 'No items found'}
                        </td>
                      </tr>
                    ) : (
                      inventory.map((item) => {
                        const status = getItemStatus(item);
                        return (
                          <React.Fragment key={item.inventory_id}>
                          <tr>
                            <td>
                              <div className="inv-product-cell">
                                <span className="inv-product-name">{getItemName(item)}</span>
                                <div className="inv-product-meta">
                                  <span className="inv-product-branch">{getBranchName(item)}</span>
                                  {getModelCode(item) && <span className="inv-product-code">{getModelCode(item)}</span>}
                                </div>
                              </div>
                            </td>
                            <td className="inv-cell-qty"><strong>{getItemQty(item)}</strong></td>
                            <td className="inv-cell-num">
                              {item.product?.product_type !== 'appliance' && item.product?.pieces_per_package > 1
                                ? (item.packages_on_hand ?? Math.floor(getItemQty(item) / item.product.pieces_per_package))
                                : <span style={{ color: '#d1d5db' }}>—</span>}
                            </td>
                            <td className="inv-cell-num">{getItemSafety(item)}</td>
                            <td className="inv-cell-num">{getItemReorder(item)}</td>
                            <td className="inv-cell-num">{getItemMax(item)}</td>
                            <td>
                              <span className={`inv-status-badge ${status.className}`}>
                                {status.label}
                              </span>
                            </td>
                            <td>
                              <div className="inv-cell-actions">
                                <button className="inv-action-link" onClick={() => { setViewingItem(item); setHistoryData(null); setDetailTab('overview'); loadInventoryHistory(item.inventory_id); }}>View</button>
                              </div>
                            </td>
                          </tr>
                          </React.Fragment>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>

              {totalPages > 1 && (() => {
                const batch = getPageBatch(currentPage, totalPages);
                return (
                  <div className="inv-pagination">
                    <button className="inv-page-btn inv-page-btn--jump" disabled={!batch.hasPrevBatch} onClick={() => setCurrentPage(batch.prevBatchPage)} aria-label="Previous batch" title="Previous batch">«</button>
                    <button className="inv-page-btn" disabled={currentPage === 1} onClick={() => setCurrentPage(currentPage - 1)}>Previous</button>
                    {batch.pages.map((page) => (
                      <button key={page} className={`inv-page-btn ${currentPage === page ? 'inv-page-btn-active' : ''}`} onClick={() => setCurrentPage(page)}>{page}</button>
                    ))}
                    <button className="inv-page-btn" disabled={currentPage === totalPages} onClick={() => setCurrentPage(currentPage + 1)}>Next</button>
                    <button className="inv-page-btn inv-page-btn--jump" disabled={!batch.hasNextBatch} onClick={() => setCurrentPage(batch.nextBatchPage)} aria-label="Next batch" title="Next batch">»</button>
                  </div>
                );
              })()}
            </div>
          </>
        )}

        {activeTab === 'inventory' && inventorySubTab === 'po-console' && (
          <div className="po-map-wrap">
            <div className="po-map-head">
              <div>
                <h3 className="inv-table-title">Inventory Batch</h3>
                <p className="inv-table-subtitle">Deliveries laid out by warehouse and zone · oldest first</p>
              </div>
              <div className="sm-search po-search">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" />
                </svg>
                <input
                  type="text"
                  placeholder="Search PO, supplier, warehouse, zone…"
                  value={poSearch}
                  onChange={(e) => setPoSearch(e.target.value)}
                />
              </div>
            </div>

            {poGroupsLoading ? (
              <div className="inv-batch-loading">Loading batches…</div>
            ) : poGroupsError ? (
              <div className="inv-batch-empty inv-batch-empty--error">{poGroupsError}</div>
            ) : (() => {
              const q = poSearch.trim().toLowerCase();
              const filtered = q
                ? poGroups.filter((g) => [g.po_number, g.supplier_name, g.warehouse_name, g.location_label]
                    .some((v) => (v || '').toLowerCase().includes(q)))
                : poGroups;

              if (filtered.length === 0) {
                return <div className="po-map-empty">No purchase orders match your search.</div>;
              }

              const byWh = new Map();
              filtered.forEach((g) => {
                if (!byWh.has(g.warehouse_id)) byWh.set(g.warehouse_id, []);
                byWh.get(g.warehouse_id).push(g);
              });
              const sections = [...byWh.values()].sort(
                (a, b) => (a[0].warehouse_name || '').localeCompare(b[0].warehouse_name || '')
              );

              return (
                <div className="po-sections">
                  {sections.map((groups) => {
                    const wh = groups[0];
                    const totalItems = groups.reduce((s, g) => s + (g.item_count || 0), 0);
                    const totalRemaining = groups.reduce((s, g) => s + (g.total_remaining || 0), 0);
                    const totalReceived = groups.reduce((s, g) => s + (g.total_received || 0), 0);
                    const oldest = groups.reduce((min, g) =>
                      (!min || (g.first_received_at || '') < (min.first_received_at || '')) ? g : min, null);
                    const oldestKey = oldest?.group_key;
                    const type = (wh.warehouse_type || '').toLowerCase();
                    return (
                      <section className="po-section" key={wh.warehouse_id}>
                        <div className="po-section-head">
                          <div className="po-section-id">
                            <span className="po-section-name">{wh.warehouse_name || '—'}</span>
                            <span className={`po-section-type po-section-type--${type || 'default'}`}>
                              {wh.warehouse_type || 'Location'}
                            </span>
                          </div>
                          <div className="po-section-totals">
                            <span>{groups.length} deliver{groups.length === 1 ? 'y' : 'ies'}</span>
                            <span>{totalItems} item{totalItems === 1 ? '' : 's'}</span>
                            <span>{totalRemaining} / {totalReceived} in stock</span>
                          </div>
                        </div>
                        <div className="po-map-grid">
                          {groups.map((g) => {
                            const pct = g.total_received > 0
                              ? Math.round((g.total_remaining / g.total_received) * 100)
                              : 0;
                            const isOldest = g.group_key === oldestKey;
                            return (
                              <div key={g.group_key} className={`po-slot${isOldest ? ' po-slot--fifo' : ''}`}>
                                {isOldest && <span className="po-slot-flag">Pick first</span>}
                                <div className="po-slot-top">
                                  {editingLocKey === g.group_key ? (
                                    <div className="po-slot-tag po-slot-tag--edit">
                                      <span className="po-slot-tag-eyebrow">Zone</span>
                                      <input
                                        type="text"
                                        className="po-slot-tag-input"
                                        value={editingLocValue}
                                        onChange={(e) => setEditingLocValue(e.target.value)}
                                        placeholder="e.g. Zone A"
                                        autoFocus
                                        onKeyDown={(e) => { if (e.key === 'Enter') saveLoc(g); if (e.key === 'Escape') setEditingLocKey(null); }}
                                      />
                                      <div className="po-slot-tag-actions">
                                        <button type="button" className="po-slot-tag-save" disabled={savingLoc} onClick={() => saveLoc(g)}>Save</button>
                                        <button type="button" className="po-slot-tag-cancel" disabled={savingLoc} onClick={() => setEditingLocKey(null)}>Cancel</button>
                                      </div>
                                    </div>
                                  ) : (
                                    <button type="button" className="po-slot-tag" onClick={() => startEditingLoc(g)} title="Edit zone">
                                      <span className="po-slot-tag-eyebrow">Zone<span className="po-slot-tag-pencil" aria-hidden="true">✎</span></span>
                                      <span className="po-slot-tag-code">{g.location_label || 'Unzoned'}</span>
                                    </button>
                                  )}
                                  <div className="po-slot-ident">
                                    <span className="po-slot-po">{g.po_number || '—'}</span>
                                    <span className="po-slot-supplier">{g.supplier_name || 'No supplier'}</span>
                                    <span className="po-slot-wh">{g.warehouse_name || '—'}</span>
                                  </div>
                                </div>

                                <div className="po-slot-meta">
                                  <div className="po-slot-meta-item">
                                    <span className="po-slot-meta-label">Received</span>
                                    <span className="po-slot-meta-value">{g.first_received_at || '—'}</span>
                                  </div>
                                  <div className="po-slot-meta-item">
                                    <span className="po-slot-meta-label">Items</span>
                                    <span className="po-slot-meta-value">{g.item_count}</span>
                                  </div>
                                </div>

                                <div className="po-slot-capacity">
                                  <div className="po-slot-bar">
                                    <div className="po-slot-bar-fill" style={{ width: `${pct}%` }} />
                                  </div>
                                  <span className="po-slot-bar-label">{g.total_remaining} / {g.total_received} in stock</span>
                                </div>

                                <div className="po-slot-foot">
                                  <button type="button" className="po-slot-view" onClick={() => openPoGroupDetail(g)}>
                                    View<span aria-hidden="true"> →</span>
                                  </button>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </section>
                    );
                  })}
                </div>
              );
            })()}
          </div>
        )}

        {/* ════════════════════════════════════════════════
            ACTIVITY LOG TAB
        ════════════════════════════════════════════════ */}
        {activeTab === 'activity' && (() => {
          // Colour map keyed on inventory_scan_events.transaction_type (varchar)
          const SCAN_COLORS = { receiving: '#16a34a', issuance: '#dc2626', transfer: '#2563eb', audit: '#d97706' };

          const term = activitySearch.toLowerCase();
          const filtered = activityLog.filter(r => {
            if (activityTypeFilter && r.transaction_type !== activityTypeFilter) return false;
            if (!term) return true;
            const ref  = (r.meta?.receiving_number || r.meta?.transfer_number || r.barcode || '').toLowerCase();
            const loc  = (r.location?.location_name || '').toLowerCase();
            const toLoc= (r.to_location?.location_name || '').toLowerCase();
            const by   = (r.user ? `${r.user.first_name || ''} ${r.user.last_name || ''}`.trim() + ' ' + (r.user.username || '') : '').toLowerCase();
            const prod = (r.product?.product_name || r.product?.product_code || '').toLowerCase();
            return ref.includes(term) || loc.includes(term) || toLoc.includes(term) || by.includes(term) || prod.includes(term);
          });

          const formatDate = (d) => d
            ? new Date(d).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
            : '—';

          const getLoc = (r) => {
            if (r.transaction_type === 'transfer') {
              const from = r.location?.location_name || '';
              const to   = r.to_location?.location_name || '';
              return from && to ? `${from} → ${to}` : from || to || '—';
            }
            return r.location?.location_name || '—';
          };

          const getBy = (r) => r.user
            ? `${r.user.first_name || ''} ${r.user.last_name || ''}`.trim() || r.user.username || '—'
            : '—';

          const getRef = (r) => r.meta?.receiving_number || r.meta?.transfer_number || r.barcode || '—';

          const qtyDisplay = (r) => {
            const q = r.quantity_delta;
            if (!q && q !== 0) return '—';
            if (r.transaction_type === 'issuance') return `-${Math.abs(q)}`;
            if (r.transaction_type === 'audit') return String(q);
            return `+${Math.abs(q)}`;
          };

          // Unique transaction types present in data for filter dropdown
          const presentTypes = [...new Set(activityLog.map(r => r.transaction_type))];

          return (
            <>
              {/* Filters */}
              <div className="sm-filter-row" style={{ marginBottom: 12 }}>
                <div className="sm-search">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" />
                  </svg>
                  <input
                    type="text"
                    placeholder="Search product, barcode, location, user..."
                    value={activitySearch}
                    onChange={(e) => setActivitySearch(e.target.value)}
                  />
                </div>
                <select
                  className="sm-select"
                  value={activityTypeFilter}
                  onChange={(e) => setActivityTypeFilter(e.target.value)}
                >
                  <option value="">All Types</option>
                  {presentTypes.map(type => {
                    const typeId = { receiving: 1, issuance: 2, transfer: 3, audit: 4 }[type];
                    const entry  = typeId ? transactionTypes.find(t => t.transaction_type_id === typeId) : null;
                    return (
                      <option key={type} value={type}>
                        {entry?.transaction_name || type}
                      </option>
                    );
                  })}
                </select>
                <span style={{ fontSize: 12, color: '#9ca3af', alignSelf: 'center' }}>
                  {activityLoading ? 'Loading…' : `${filtered.length} record${filtered.length !== 1 ? 's' : ''}`}
                </span>
              </div>

              {/* Table */}
              <div className="sm-card sm-products-card" style={{ padding: 0 }}>
                <div className="sm-products-header" style={{ padding: '16px 20px 12px' }}>
                  <h3 className="sm-card-title">Stock Movement Audit Trail</h3>
                  <p className="sm-card-sub">Every barcode scan that changed inventory — receiving, issuance, transfer, audit</p>
                </div>

                {activityLoading && <Loader label="Loading activity log" />}

                {!activityLoading && (
                  <div className="inv-table-wrapper">
                    <table className="inv-table">
                      <thead>
                        <tr>
                          <th style={{ width: 40 }}>#</th>
                          <th>DATE / TIME</th>
                          <th>TYPE</th>
                          <th>PRODUCT</th>
                          <th>BARCODE / REF</th>
                          <th>LOCATION</th>
                          <th style={{ textAlign: 'right' }}>QTY</th>
                          <th>BY</th>
                        </tr>
                      </thead>
                      <tbody>
                        {filtered.length === 0 ? (
                          <tr>
                            <td colSpan="8" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                              {activityLog.length === 0
                                ? 'No scan events recorded yet. Events will appear here after the first barcode scan.'
                                : 'No records match your filters.'}
                            </td>
                          </tr>
                        ) : filtered.map((r, idx) => {
                          const color = SCAN_COLORS[r.transaction_type] || '#6b7280';
                          const label = r.transaction_type_name || r.transaction_type || '—';
                          const qtyColor = r.transaction_type === 'issuance' ? '#dc2626'
                            : r.transaction_type === 'transfer' ? '#2563eb'
                            : r.transaction_type === 'audit' ? '#d97706'
                            : '#16a34a';
                          return (
                            <tr key={r.id}>
                              <td className="inv-cell-num" style={{ color: '#9ca3af', fontSize: 11 }}>{idx + 1}</td>
                              <td style={{ whiteSpace: 'nowrap', fontSize: 12 }}>{formatDate(r.created_at)}</td>
                              <td>
                                <span style={{
                                  display: 'inline-block', fontSize: 10, fontWeight: 700,
                                  letterSpacing: '0.04em', padding: '2px 8px', borderRadius: 4,
                                  background: color + '18', color, border: `1px solid ${color}40`,
                                }}>
                                  {label.toUpperCase()}
                                </span>
                              </td>
                              <td>
                                <div style={{ fontSize: 12, fontWeight: 600, color: '#111827' }}>{r.product?.product_name || '—'}</div>
                                {r.product?.applianceDetail?.model_code && <div style={{ fontSize: 10, color: '#9ca3af' }}>{r.product.applianceDetail.model_code}</div>}
                              </td>
                              <td><span className="invd-mono invd-small">{getRef(r)}</span></td>
                              <td style={{ fontSize: 12, color: '#374151' }}>{getLoc(r)}</td>
                              <td style={{ textAlign: 'right', fontWeight: 600, fontSize: 13, color: qtyColor }}>
                                {qtyDisplay(r)}
                              </td>
                              <td style={{ fontSize: 12, color: '#6b7280' }}>{getBy(r)}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </>
          );
        })()}

        {/* ── Modals ── */}
        {viewingItem && (
          <Modal
            isOpen={!!viewingItem}
            onClose={() => { setViewingItem(null); setHistoryData(null); setDetailTab('overview'); }}
            title={getItemName(viewingItem)}
            maxWidth={920}
          >
            <div className={`inv-view-details${invPulse ? ' ak-focus-pulse' : ''}`}>

              {/* ── Tab navigation ── */}
              <div className="invd-tabs">
                <button className={`invd-tab-btn${detailTab === 'overview' ? ' active' : ''}`} onClick={() => setDetailTab('overview')}>
                  Overview
                </button>
                <button className={`invd-tab-btn${detailTab === 'history' ? ' active' : ''}`} onClick={() => setDetailTab('history')}>
                  Stock-In History
                  {historyData && <span className="invd-tab-count">{historyData.summary.total_receive_events}</span>}
                </button>
                <button className={`invd-tab-btn${detailTab === 'stock-out' ? ' active' : ''}`} onClick={() => setDetailTab('stock-out')}>
                  Stock-Out History
                  {historyData && <span className="invd-tab-count">{stockOutHistory.length}</span>}
                </button>
                <button className={`invd-tab-btn${detailTab === 'transfers' ? ' active' : ''}`} onClick={() => setDetailTab('transfers')}>
                  Transfer History
                  {historyData && <span className="invd-tab-count">{transferHistory.length}</span>}
                </button>
                {historyData && (
                  <button className={`invd-tab-btn${detailTab === 'serials' ? ' active' : ''}`} onClick={() => setDetailTab('serials')}>
                    Serial Numbers
                    <span className="invd-tab-count">{historyData.summary.serial_count}</span>
                  </button>
                )}
              </div>

              {/* ── Loading / error ── */}
              {historyLoading && <Loader label="Loading stock history" />}
              {historyError && <div className="invd-error-msg">{historyError}</div>}

              {/* ══════════════════════════════
                  TAB: OVERVIEW
              ══════════════════════════════ */}
              {detailTab === 'overview' && (
                <div className="invd-panel">

                  <div className="invd-section">
                    <div className="invd-section-title">Product Information</div>
                    <div className="invd-grid">
                      <div className="invd-field">
                        <span className="invd-label">Product Name</span>
                        <span className="invd-value">{historyData?.product?.product_name ?? getItemName(viewingItem)}</span>
                      </div>
                      <div className="invd-field">
                        <span className="invd-label">Model Code</span>
                        <span className="invd-value invd-mono">{historyData?.product?.applianceDetail?.model_code || getModelCode(viewingItem) || '—'}</span>
                      </div>
                      <div className="invd-field">
                        <span className="invd-label">Type</span>
                        <span className={`invd-type-badge ${historyData?.product?.product_type === 'appliance' ? 'invd-type-appliance' : 'invd-type-consumable'}`}>
                          {historyData?.product?.product_type === 'appliance' ? 'Appliance' : 'Consumable'}
                        </span>
                      </div>
                      <div className="invd-field">
                        <span className="invd-label">Category</span>
                        <span className="invd-value">{historyData?.product?.category_name ?? getItemCat(viewingItem)}</span>
                      </div>
                      {historyData?.product?.brand_name && (
                        <div className="invd-field">
                          <span className="invd-label">Brand</span>
                          <span className="invd-value">{historyData.product.brand_name}</span>
                        </div>
                      )}
                      {historyData?.product?.capacity_rating && (
                        <div className="invd-field">
                          <span className="invd-label">Capacity</span>
                          <span className="invd-value">{historyData.product.capacity_rating}</span>
                        </div>
                      )}
                      {historyData?.product?.variant && (
                        <div className="invd-field">
                          <span className="invd-label">Variant</span>
                          <span className="invd-value">{historyData.product.variant}</span>
                        </div>
                      )}
                      {historyData?.product?.warranty_months && (
                        <div className="invd-field">
                          <span className="invd-label">Warranty</span>
                          <span className="invd-value">{historyData.product.warranty_months} months</span>
                        </div>
                      )}
                      {historyData?.product?.unit_name && (
                        <div className="invd-field">
                          <span className="invd-label">Unit</span>
                          <span className="invd-value">
                            {historyData.product.unit_name}
                            {historyData.product.unit_abbr ? ` (${historyData.product.unit_abbr})` : ''}
                          </span>
                        </div>
                      )}
                      {historyData?.product?.pieces_per_package > 1 && (
                        <div className="invd-field">
                          <span className="invd-label">Pieces / Package</span>
                          <span className="invd-value">{historyData.product.pieces_per_package}</span>
                        </div>
                      )}
                    </div>
                  </div>

                  <div className="invd-section">
                    <div className="invd-section-title">Stock Information</div>
                    <div className="invd-grid">
                      <div className="invd-field">
                        <span className="invd-label">Location</span>
                        <span className="invd-value">{historyData?.inventory?.location_name ?? getBranchName(viewingItem)}</span>
                      </div>
                      {historyData?.inventory?.location_type && (
                        <div className="invd-field">
                          <span className="invd-label">Location Type</span>
                          <span className="invd-value invd-cap">{historyData.inventory.location_type.replace(/_/g, ' ')}</span>
                        </div>
                      )}
                      <div className="invd-field">
                        <span className="invd-label">Qty on Hand</span>
                        <span className="invd-value invd-qty">{historyData?.inventory?.quantity_on_hand ?? getItemQty(viewingItem)}</span>
                      </div>
                      {historyData?.product?.is_serialized === false && (
                        <div className="invd-field">
                          <span className="invd-label">Packages on Hand</span>
                          <span className="invd-value">{historyData?.inventory?.packages_on_hand ?? '—'}</span>
                        </div>
                      )}
                      <div className="invd-field">
                        <span className="invd-label">Available Qty</span>
                        <span className="invd-value">{historyData?.inventory?.available_quantity ?? '—'}</span>
                      </div>
                      <div className="invd-field">
                        <span className="invd-label">Reorder Level</span>
                        <span className="invd-value">{historyData?.inventory?.reorder_level ?? getItemReorder(viewingItem)}</span>
                      </div>
                      <div className="invd-field">
                        <span className="invd-label">Stock Status</span>
                        <span className={`inv-status-badge ${getItemStatus(viewingItem).className}`}>{getItemStatus(viewingItem).label}</span>
                      </div>
                      {historyData?.inventory?.last_updated && (
                        <div className="invd-field">
                          <span className="invd-label">Last Updated</span>
                          <span className="invd-value">
                            {new Date(historyData.inventory.last_updated).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' })}
                          </span>
                        </div>
                      )}
                    </div>
                  </div>

                  {historyData && (
                    <div className="invd-section">
                      <div className="invd-section-title">Cost Summary</div>
                      <div className="invd-grid">
                        <div className="invd-field">
                          <span className="invd-label">
                            {historyData.product.pieces_per_package > 1 ? 'Package Cost' : 'Cost Price'}
                          </span>
                          <span className="invd-value invd-currency">
                            ₱{parseFloat(historyData.product.cost_price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                          </span>
                        </div>
                        {historyData.product.pieces_per_package > 1 && (
                          <div className="invd-field">
                            <span className="invd-label">Cost Per Piece</span>
                            <span className="invd-value invd-currency">
                              ₱{parseFloat(historyData.product.cost_per_piece || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}
                            </span>
                          </div>
                        )}
                        <div className="invd-field">
                          <span className="invd-label">Avg Received Cost</span>
                          <span className="invd-value invd-currency">
                            ₱{parseFloat(historyData.summary.avg_cost_price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                          </span>
                        </div>
                        <div className="invd-field">
                          <span className="invd-label">Total Stock Value</span>
                          <span className="invd-value invd-currency invd-bold">
                            ₱{parseFloat(historyData.summary.current_stock_value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                          </span>
                        </div>
                        {historyData.summary.last_stocked_at && (
                          <div className="invd-field">
                            <span className="invd-label">Last Stocked</span>
                            <span className="invd-value">
                              {new Date(historyData.summary.last_stocked_at).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' })}
                            </span>
                          </div>
                        )}
                        {historyData.summary.latest_po_number && (
                          <div className="invd-field">
                            <span className="invd-label">Latest PO</span>
                            <span className="invd-value invd-mono">{historyData.summary.latest_po_number}</span>
                          </div>
                        )}
                        {historyData.summary.latest_supplier && (
                          <div className="invd-field">
                            <span className="invd-label">Latest Supplier</span>
                            <span className="invd-value">{historyData.summary.latest_supplier}</span>
                          </div>
                        )}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* ══════════════════════════════
                  TAB: STOCK-IN HISTORY
              ══════════════════════════════ */}
              {detailTab === 'history' && !historyLoading && (
                <div className="invd-panel">
                  {!historyData || historyData.receiving_history.length === 0 ? (
                    <div className="invd-empty">No stock-in records found for this product at this location.</div>
                  ) : (
                    <div className="invd-table-wrap">
                      <table className="invd-table">
                        <thead>
                          <tr>
                            <th>#</th>
                            <th>Date Received</th>
                            <th>Barcode / Package</th>
                            <th>Qty</th>
                            <th>Cost Price</th>
                            <th>Received By</th>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Condition</th>
                          </tr>
                        </thead>
                        <tbody>
                          {historyData.receiving_history.map((rd, index) => (
                            <tr key={rd.receiving_detail_id}>
                              <td className="invd-cell-num">{index + 1}</td>
                              <td>
                                <span className="invd-date">
                                  {rd.received_at
                                    ? new Date(rd.received_at).toLocaleString('en-PH', {
                                        year: 'numeric', month: 'short', day: 'numeric',
                                        hour: '2-digit', minute: '2-digit', second: '2-digit',
                                      })
                                    : '—'}
                                </span>
                                {rd.receiving_number && <span className="invd-sub">{rd.receiving_number}</span>}
                              </td>
                              <td>
                                {rd.scanned_barcode
                                  ? <span className="invd-mono">{rd.scanned_barcode}</span>
                                  : <span className="invd-muted">—</span>}
                              </td>
                              <td className="invd-cell-num invd-bold">{rd.quantity_amount}</td>
                              <td className="invd-cell-currency">
                                ₱{parseFloat(rd.prod_price).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                              </td>
                              <td>{rd.received_by_name ?? '—'}</td>
                              <td>
                                {rd.po_number
                                  ? <span className="invd-mono invd-small">{rd.po_number}</span>
                                  : <span className="invd-muted">—</span>}
                                {rd.po_date && <span className="invd-sub">{new Date(rd.po_date).toLocaleDateString('en-PH')}</span>}
                              </td>
                              <td>
                                <span>{rd.supplier_name ?? '—'}</span>
                                {rd.supplier_contact && <span className="invd-sub">{rd.supplier_contact}</span>}
                              </td>
                              <td>
                                <span className={`invd-cond-badge${rd.condition === 'Good' ? ' invd-cond-good' : rd.condition ? ' invd-cond-warn' : ''}`}>
                                  {rd.condition ?? '—'}
                                </span>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

              {/* ══════════════════════════════
                  TAB: SERIAL NUMBERS
              ══════════════════════════════ */}
              {(detailTab === 'stock-out' || detailTab === 'transfers') && !historyLoading && historyData && (() => {
                const rows = detailTab === 'stock-out' ? stockOutHistory : transferHistory;
                const emptyLabel = detailTab === 'stock-out'
                  ? 'No stock-out records found for this product at this location.'
                  : 'No transfer records found for this product at this location.';
                return (
                  <div className="invd-panel">
                    {rows.length === 0 ? <div className="invd-empty">{emptyLabel}</div> : (
                      <div className="invd-table-wrap">
                        <table className="invd-table">
                          <thead><tr>
                            <th>#</th><th>Date &amp; Time</th><th>Quantity</th>
                            <th>Reference</th><th>Serial / Barcode</th>
                            <th>From</th><th>To</th><th>Performed By</th>
                          </tr></thead>
                          <tbody>
                            {rows.map((movement, index) => (
                              <tr key={`${movement.type}-${movement.reference || index}-${index}`}>
                                <td className="invd-cell-num">{index + 1}</td>
                                <td>{movement.date_time ? new Date(movement.date_time).toLocaleString('en-PH', {
                                  year: 'numeric', month: 'short', day: 'numeric',
                                  hour: '2-digit', minute: '2-digit', second: '2-digit',
                                }) : '—'}</td>
                                <td className="invd-cell-num invd-bold">{movement.quantity}</td>
                                <td className="invd-mono">{movement.reference || '—'}</td>
                                <td className="invd-mono">{movement.serial_number || '—'}</td>
                                <td>{movement.from_location || '—'}</td>
                                <td>{movement.to_location || '—'}</td>
                                <td>{movement.performed_by || '—'}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                );
              })()}

              {detailTab === 'serials' && !historyLoading && historyData && (
                <div className="invd-panel">
                  <div className="invd-move-stats">
                    <div className="invd-move-stat invd-move-in">
                      <span className="invd-move-label">Stock In</span>
                      <span className="invd-move-value">{historyData.summary.stock_in ?? 0}</span>
                    </div>
                    <div className="invd-move-stat invd-move-out">
                      <span className="invd-move-label">Stock Out</span>
                      <span className="invd-move-value">{historyData.summary.stock_out ?? 0}</span>
                    </div>
                    <div className="invd-move-stat invd-move-transfer">
                      <span className="invd-move-label">Transfers</span>
                      <span className="invd-move-value">{historyData.summary.transferred ?? 0}</span>
                    </div>
                    <div className="invd-move-stat">
                      <span className="invd-move-label">Current On Hand</span>
                      <span className="invd-move-value">{historyData.summary.current_on_hand ?? historyData.inventory?.quantity_on_hand ?? 0}</span>
                    </div>
                    <div className="invd-move-stat">
                      <span className="invd-move-label">Available</span>
                      <span className="invd-move-value">{historyData.summary.current_available ?? historyData.inventory?.available_quantity ?? 0}</span>
                    </div>
                  </div>
                  {!historyData.serial_records || historyData.serial_records.length === 0 ? (
                    <div className="invd-empty">No serial numbers recorded for this product at this location.</div>
                  ) : (
                    <div className="invd-table-wrap">
                      <table className="invd-table">
                        <thead>
                          <tr>
                            <th>#</th>
                            <th>Serial Number</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th>Stock In</th>
                            <th>Stock Out</th>
                            <th>Transfer</th>
                            <th>Scanned By</th>
                            <th>Received At</th>
                            <th>Stocked Out At</th>
                            <th>Transferred At</th>
                            <th>PO Number</th>
                            <th>Supplier</th>
                          </tr>
                        </thead>
                        <tbody>
                          {historyData.serial_records.map((s, index) => (
                            <tr key={s.serial_id ?? index}>
                              <td className="invd-cell-num">{index + 1}</td>
                              <td><span className="invd-mono invd-bold">{s.serial_number}</span></td>
                              <td>
                                <span className={`invd-status-pill${
                                  s.serial_status === 'In Stock' ? ' invd-pill-green'
                                  : s.serial_status === 'Issued' ? ' invd-pill-orange'
                                  : ' invd-pill-gray'
                                }`}>
                                  {s.serial_status ?? 'Active'}
                                </span>
                              </td>
                              <td className="invd-mono">{s.row_quantity_received ?? s.row_stock_in ?? 0}</td>
                              <td className="invd-mono">{s.row_stock_in ?? '—'}</td>
                              <td className="invd-mono">{s.row_stock_out ?? '—'}</td>
                              <td className="invd-mono">{s.row_transfer ?? '—'}</td>
                              <td>{s.scanned_by_name ?? '—'}</td>
                              <td>
                                {s.received_at
                                  ? new Date(s.received_at).toLocaleString('en-PH', {
                                      year: 'numeric', month: 'short', day: 'numeric',
                                      hour: '2-digit', minute: '2-digit',
                                    })
                                  : '—'}
                              </td>
                              <td>{s.stock_out_at ? new Date(s.stock_out_at).toLocaleString('en-PH', {
                                year: 'numeric', month: 'short', day: 'numeric',
                                hour: '2-digit', minute: '2-digit',
                              }) : '—'}</td>
                              <td>{s.transferred_at ? new Date(s.transferred_at).toLocaleString('en-PH', {
                                year: 'numeric', month: 'short', day: 'numeric',
                                hour: '2-digit', minute: '2-digit',
                              }) : '—'}</td>
                              <td>
                                {s.po_number
                                  ? <span className="invd-mono invd-small">{s.po_number}</span>
                                  : '—'}
                              </td>
                              <td>{s.supplier_name ?? '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {historyData.movement_history?.length > 0 && (
                    <div className="invd-table-wrap" style={{ marginTop: 24 }}>
                      <div className="invd-section-title">Movement Timeline</div>
                      <table className="invd-table">
                        <thead><tr>
                          <th>Movement</th><th>Quantity</th><th>Date &amp; Time</th>
                          <th>Reference</th><th>Serial / Barcode</th>
                          <th>From</th><th>To</th><th>Performed By</th>
                        </tr></thead>
                        <tbody>
                          {historyData.movement_history.map((movement, index) => (
                            <tr key={`${movement.type}-${movement.reference || index}-${index}`}>
                              <td>{movement.type}</td>
                              <td className="invd-mono">{movement.quantity}</td>
                              <td>{movement.date_time ? new Date(movement.date_time).toLocaleString('en-PH', {
                                year: 'numeric', month: 'short', day: 'numeric',
                                hour: '2-digit', minute: '2-digit', second: '2-digit',
                              }) : '—'}</td>
                              <td className="invd-mono">{movement.reference || '—'}</td>
                              <td className="invd-mono">{movement.serial_number || '—'}</td>
                              <td>{movement.from_location || '—'}</td>
                              <td>{movement.to_location || '—'}</td>
                              <td>{movement.performed_by || '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

              <div className="inv-modal-footer">
                <button className="inv-modal-btn-cancel" onClick={() => { setViewingItem(null); setHistoryData(null); setDetailTab('overview'); }}>Close</button>
              </div>
            </div>
          </Modal>
        )}

        {serialModalBatch && (
          <Modal
            isOpen={!!serialModalBatch}
            onClose={() => setSerialModalBatch(null)}
            title={`Serial Numbers — ${serialModalBatch.title}`}
            maxWidth={560}
          >
            <div className="inv-serial-modal">
              <div className="inv-serial-modal-count">
                {serialModalBatch.serials.length} serial number{serialModalBatch.serials.length === 1 ? '' : 's'}
              </div>
              <div className="inv-serial-chips">
                {serialModalBatch.serials.map((sn, i) => (
                  <span className="inv-serial-chip" key={i}>{sn}</span>
                ))}
              </div>
            </div>
          </Modal>
        )}

        {poGroupDetail && (
          <Modal
            isOpen={!!poGroupDetail}
            onClose={() => { setPoGroupDetail(null); setPoDetailTab('overview'); }}
            title={`${poGroupDetail.group?.po_number || 'PO'} — ${poGroupDetail.group?.warehouse_name || ''}`}
            maxWidth={920}
          >
            <div className="inv-view-details">
              <div className="invd-tabs">
                <button className={`invd-tab-btn${poDetailTab === 'overview' ? ' active' : ''}`} onClick={() => setPoDetailTab('overview')}>Overview</button>
                <button className={`invd-tab-btn${poDetailTab === 'history' ? ' active' : ''}`} onClick={() => setPoDetailTab('history')}>Stock-In History</button>
                <button className={`invd-tab-btn${poDetailTab === 'stock-out' ? ' active' : ''}`} onClick={() => setPoDetailTab('stock-out')}>Stock-Out History</button>
                <button className={`invd-tab-btn${poDetailTab === 'transfers' ? ' active' : ''}`} onClick={() => setPoDetailTab('transfers')}>Transfer History</button>
                <button className={`invd-tab-btn${poDetailTab === 'serials' ? ' active' : ''}`} onClick={() => setPoDetailTab('serials')}>Serial Numbers</button>
              </div>

              {poGroupDetailLoading && <Loader label="Loading PO details" />}
              {poGroupDetail.error && <div className="invd-error-msg">{poGroupDetail.error}</div>}

              {!poGroupDetailLoading && poGroupDetail.data && (
                <>
                  {poDetailTab === 'overview' && (
                    <div className="invd-panel">
                      <div className="invd-section">
                        <div className="invd-section-title">PO Information</div>
                        <div className="invd-grid">
                          <div className="invd-field"><span className="invd-label">PO Number</span><span className="invd-value invd-mono">{poGroupDetail.data.overview.po_number || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Supplier</span><span className="invd-value">{poGroupDetail.data.overview.supplier_name || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Order Date</span><span className="invd-value">{poGroupDetail.data.overview.order_date || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Warehouse</span><span className="invd-value">{poGroupDetail.data.overview.warehouse_name || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Zone</span><span className="invd-value">{poGroupDetail.data.overview.location_label || '—'}</span></div>
                          <div className="invd-field"><span className="invd-label">Items</span><span className="invd-value">{poGroupDetail.data.overview.totals.item_count}</span></div>
                        </div>
                      </div>
                      <div className="invd-section">
                        <div className="invd-section-title">Products ({poGroupDetail.data.overview.totals.total_remaining} / {poGroupDetail.data.overview.totals.total_received} remaining)</div>
                        <div className="invd-table-wrap">
                          <table className="invd-table">
                            <thead><tr><th>Product</th><th>Qty Remaining</th><th>Qty Received</th></tr></thead>
                            <tbody>
                              {poGroupDetail.data.overview.products.map((p) => (
                                <tr key={p.product_id}><td>{p.product_name}</td><td>{p.quantity_remaining}</td><td>{p.quantity_received}</td></tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  )}

                  {poDetailTab === 'history' && (
                    <div className="invd-panel">
                      {poGroupDetail.data.receiving_history.length === 0 ? (
                        <div className="invd-empty">No receiving history.</div>
                      ) : (
                        <div className="invd-table-wrap">
                          <table className="invd-table">
                            <thead><tr><th>Receiving #</th><th>Date</th><th>Received By</th><th>Product</th><th>Qty</th><th>Barcode</th></tr></thead>
                            <tbody>
                              {poGroupDetail.data.receiving_history.map((r, i) => (
                                <tr key={i}>
                                  <td className="invd-mono invd-small">{r.receiving_number || '—'}</td>
                                  <td>{r.received_at ? new Date(r.received_at).toLocaleDateString('en-PH') : '—'}</td>
                                  <td>{r.received_by_name?.trim() || '—'}</td>
                                  <td>{r.product_name}</td>
                                  <td>{r.quantity_amount}</td>
                                  <td className="invd-mono invd-small">{r.scanned_barcode || '—'}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  )}

                  {poDetailTab === 'serials' && (
                    <div className="invd-panel">
                      {poGroupDetail.data.serials.length === 0 ? (
                        <div className="invd-empty">No serial numbers recorded for this PO at this location.</div>
                      ) : (
                        <div className="invd-table-wrap">
                          <table className="invd-table">
                            <thead>
                              <tr>
                                <th>#</th>
                                <th>Serial Number</th>
                                <th>Product</th>
                                <th>Status</th>
                                <th>Scanned By</th>
                                <th>Date Scanned</th>
                              </tr>
                            </thead>
                            <tbody>
                              {poGroupDetail.data.serials.map((s, index) => (
                                <tr key={index}>
                                  <td className="invd-cell-num">{index + 1}</td>
                                  <td><span className="invd-mono invd-bold">{s.serial_number}</span></td>
                                  <td>{s.product_name}</td>
                                  <td>
                                    <span className={`invd-status-pill${
                                      s.serial_status === 'In Stock' ? ' invd-pill-green'
                                      : s.serial_status === 'Issued' ? ' invd-pill-orange'
                                      : ' invd-pill-gray'
                                    }`}>
                                      {s.serial_status ?? 'Active'}
                                    </span>
                                  </td>
                                  <td>{s.scanned_by_name ?? '—'}</td>
                                  <td>
                                    {s.scanned_at
                                      ? new Date(s.scanned_at).toLocaleString('en-PH', {
                                          year: 'numeric', month: 'short', day: 'numeric',
                                          hour: '2-digit', minute: '2-digit',
                                        })
                                      : '—'}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </div>
                  )}

                  {(poDetailTab === 'stock-out' || poDetailTab === 'transfers') && (() => {
                    const rows = poDetailTab === 'stock-out'
                      ? poGroupDetail.data.stock_out_history
                      : poGroupDetail.data.transfer_history;
                    return (
                      <div className="invd-panel">
                        {!rows?.length ? (
                          <div className="invd-empty">No {poDetailTab === 'stock-out' ? 'stock-out' : 'transfer'} history for these batches.</div>
                        ) : (
                          <div className="invd-table-wrap">
                            <table className="invd-table">
                              <thead><tr><th>Batch</th><th>Date & Time</th><th>Reference</th><th>Product</th><th>Qty</th><th>Serial Number</th>{poDetailTab === 'transfers' && <th>Destination</th>}<th>Performed By</th></tr></thead>
                              <tbody>
                                {rows.map((r) => (
                                  <tr key={r.movement_id}>
                                    <td className="invd-mono">#{r.batch_id}</td>
                                    <td>{r.moved_at ? new Date(r.moved_at).toLocaleString('en-PH') : '—'}</td>
                                    <td className="invd-mono invd-small">{r.reference || '—'}</td>
                                    <td>{r.product_name}</td>
                                    <td>{r.quantity}</td>
                                    <td className="invd-mono invd-small">{r.serial_number || '—'}</td>
                                    {poDetailTab === 'transfers' && <td>{r.to_location || '—'}</td>}
                                    <td>{r.performed_by || '—'}</td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        )}
                      </div>
                    );
                  })()}
                </>
              )}
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
  );
};

export default InventoryManagement;
