import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Modal from '../components/Modal';
import { inventoryAPI, locationsAPI, categoriesAPI, batchAPI, dashboardAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/inventory_management.css';

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
  const [activeTab, setActiveTab] = useState('stock-movement');

  // Inventory state
  const [inventory, setInventory] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1 });
  const [viewingItem, setViewingItem] = useState(null);
  const [editingItem, setEditingItem] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [smSearch, setSmSearch] = useState('');
  const [trendData, setTrendData] = useState(null);
  const [trendLoading, setTrendLoading] = useState(false);
  const rowsPerPage = 10;
  const debounceRef = useRef(null);

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
      const res = await inventoryAPI.getAll(params);
      setInventory(Array.isArray(res.data) ? res.data : []);
      setPagination(res.pagination || { total: 0, last_page: 1 });
      setError(null);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }, [searchTerm, rowsPerPage]);

  useEffect(() => { fetchInventory(currentPage); }, [currentPage, fetchInventory]);

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => setCurrentPage(1), 400);
    return () => clearTimeout(debounceRef.current);
  }, [searchTerm]);

  /* ── helpers ─── */
  const getItemName   = (item) => item.product?.product_name || item.item?.name || item.item_name || 'N/A';
  const getItemCode   = (item) => item.product?.product_code || item.item?.code || item.sku || '';
  const getItemQty    = (item) => item.quantity || item.quantity_on_hand || 0;
  const getItemReorder = (item) => item.reorder_level || item.item?.reorder_level || 5;
  const getItemSafety  = (item) => Math.round(getItemReorder(item) * 0.25) || 3;
  const getItemMax     = (item) => getItemReorder(item) * 4 || 100;
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

  return (
    <AdminLayout>
      <div className="inv-page">

        {/* ── Page header ── */}
        <div className="inv-page-header">
          <div>
            <h1 className="inv-page-title">Inventory Monitoring</h1>
            <p className="inv-page-subtitle">
              {activeTab === 'stock-movement'
                ? 'Comprehensive stock in/out analysis and inventory velocity'
                : 'Real-time inventory levels and safety stock tracking across all Mindanao locations'}
            </p>
          </div>
        </div>

        {/* ── Tab navigation ── */}
        <div className="inv-tabs">
          <button
            className={`inv-tab-btn ${activeTab === 'stock-movement' ? 'active' : ''}`}
            onClick={() => setActiveTab('stock-movement')}
          >
            Stock Movement &amp; Turnover
          </button>
          <button
            className={`inv-tab-btn ${activeTab === 'inventory' ? 'active' : ''}`}
            onClick={() => setActiveTab('inventory')}
          >
            Inventory
          </button>
        </div>

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

              {loading && <div className="inv-loading">Loading...</div>}

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
                      <tr key={item.id}>
                        <td>
                          <div className="sm-rank-cell">
                            <span className="sm-rank">#{String(rank).padStart(2, '0')}</span>
                            <div>
                              <div className="inv-product-name">{getItemName(item)}</div>
                              <div className="inv-product-code">{getItemCode(item)}</div>
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
        {activeTab === 'inventory' && (
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

            {loading && <div className="inv-loading">Loading inventory...</div>}
            {error && <div className="inv-error">Error: {error}</div>}

            {/* Inventory Console Table */}
            <div className="inv-table-card">
              <div className="inv-table-top">
                <div>
                  <h3 className="inv-table-title">Inventory Monitoring Console</h3>
                  <p className="inv-table-subtitle">Monitoring {totalItems} SKUs across branches</p>
                </div>
              </div>

              <div className="inv-table-wrapper">
                <table className="inv-table">
                  <thead>
                    <tr>
                      <th>PRODUCT &amp; BRANCH</th>
                      <th>CURRENT</th>
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
                        <td colSpan="7" style={{ textAlign: 'center', color: '#9ca3af', padding: '40px' }}>
                          {loading ? 'Loading...' : 'No items found'}
                        </td>
                      </tr>
                    ) : (
                      inventory.map((item) => {
                        const status = getItemStatus(item);
                        return (
                          <tr key={item.id}>
                            <td>
                              <div className="inv-product-cell">
                                <span className="inv-product-name">{getItemName(item)}</span>
                                <div className="inv-product-meta">
                                  <span className="inv-product-branch">{getBranchName(item)}</span>
                                  <span className="inv-product-code">{getItemCode(item)}</span>
                                </div>
                              </div>
                            </td>
                            <td className="inv-cell-qty"><strong>{getItemQty(item)}</strong></td>
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
                                <button className="inv-action-link" onClick={() => setViewingItem(item)}>View</button>
                                <button className="inv-action-link inv-action-edit" onClick={() => setEditingItem(item)}>Edit</button>
                              </div>
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>

              {totalPages > 1 && (
                <div className="inv-pagination">
                  <button className="inv-page-btn" disabled={currentPage === 1} onClick={() => setCurrentPage(currentPage - 1)}>Previous</button>
                  {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => (
                    <button key={page} className={`inv-page-btn ${currentPage === page ? 'inv-page-btn-active' : ''}`} onClick={() => setCurrentPage(page)}>{page}</button>
                  ))}
                  <button className="inv-page-btn" disabled={currentPage === totalPages} onClick={() => setCurrentPage(currentPage + 1)}>Next</button>
                </div>
              )}
            </div>
          </>
        )}

        {/* ── Modals ── */}
        {viewingItem && (
          <Modal isOpen={!!viewingItem} onClose={() => setViewingItem(null)} title="Inventory Details">
            <div className="inv-view-details">
              <div className="inv-view-row">
                <div className="inv-view-field"><label>Item Name</label><p>{getItemName(viewingItem)}</p></div>
                <div className="inv-view-field"><label>SKU/Code</label><p>{getItemCode(viewingItem)}</p></div>
              </div>
              <div className="inv-view-row">
                <div className="inv-view-field"><label>Branch</label><p>{getBranchName(viewingItem)}</p></div>
                <div className="inv-view-field"><label>Current Qty</label><p>{getItemQty(viewingItem)}</p></div>
              </div>
              <div className="inv-view-row">
                <div className="inv-view-field"><label>Reorder Level</label><p>{getItemReorder(viewingItem)}</p></div>
                <div className="inv-view-field"><label>Status</label><p><span className={`inv-status-badge ${getItemStatus(viewingItem).className}`}>{getItemStatus(viewingItem).label}</span></p></div>
              </div>
              <div className="inv-modal-footer">
                <button className="inv-modal-btn-cancel" onClick={() => setViewingItem(null)}>Close</button>
              </div>
            </div>
          </Modal>
        )}

        {editingItem && (
          <Modal isOpen={!!editingItem} onClose={() => setEditingItem(null)} title="Edit Inventory">
            <div className="inv-view-details">
              <p style={{ color: '#6b7280', fontSize: 13 }}>Edit functionality for <strong>{getItemName(editingItem)}</strong> coming soon.</p>
              <div className="inv-modal-footer">
                <button className="inv-modal-btn-cancel" onClick={() => setEditingItem(null)}>Close</button>
              </div>
            </div>
          </Modal>
        )}
      </div>
    </AdminLayout>
  );
};

export default InventoryManagement;
