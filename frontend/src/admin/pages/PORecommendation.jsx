import React, { useState, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { inventoryAPI, productsAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/po_recommendation.css';

/** Same stock figure as Item Master: sum of inventory.quantity_on_hand (from API stock_on_hand_total or client sum). */
function warehouseStockOnHand(item, totalsByProductId) {
  const fromApi = item.product?.stock_on_hand_total;
  if (fromApi !== null && fromApi !== undefined && fromApi !== '') {
    const n = Number(fromApi);
    if (!Number.isNaN(n)) return n;
  }
  const pid = item.product_id ?? item.product?.product_id;
  if (pid != null && pid !== '' && totalsByProductId.has(Number(pid))) {
    return totalsByProductId.get(Number(pid));
  }
  return Number(item.quantity_on_hand ?? 0);
}

function getPriority(item, stockOnHand) {
  const qoh = Number(stockOnHand) || 0;
  const threshold =
    (item.reorder_level ?? 0) || (item.product?.recommended_stocks ?? 0);
  if (qoh === 0) return 'CRITICAL';
  if (threshold > 0) {
    if (qoh <= Math.max(1, Math.floor(threshold * 0.25))) return 'CRITICAL';
    if (qoh <= Math.max(1, Math.floor(threshold * 0.5))) return 'HIGH';
    return 'MEDIUM';
  }
  return 'MEDIUM';
}

const PRIORITY_CFG = {
  CRITICAL: { label: 'CRITICAL',      color: '#dc2626', bg: '#fef2f2', border: '#fecaca', barColor: '#dc2626' },
  HIGH:     { label: 'HIGH PRIORITY', color: '#d97706', bg: '#fffbeb', border: '#fde68a', barColor: '#d97706' },
  MEDIUM:   { label: 'MEDIUM',        color: '#2563eb', bg: '#eff6ff', border: '#bfdbfe', barColor: '#2563eb' },
};

/** All product_id values from Item Master (paginated API) — PO Recommendation only lists rows tied to these. */
async function fetchItemMasterProductIdSet() {
  const ids = new Set();
  let page = 1;
  let lastPage = 1;
  const perPage = 200;
  do {
    const res = await productsAPI.getAll({ page, per_page: perPage });
    const list = Array.isArray(res?.data) ? res.data : [];
    list.forEach((p) => {
      const id = Number(p.product_id);
      if (Number.isFinite(id) && id > 0) ids.add(id);
    });
    lastPage = Math.max(1, Number(res?.pagination?.last_page) || 1);
    page += 1;
  } while (page <= lastPage);
  return ids;
}

const PORecommendation = () => {
  const navigate = useNavigate();

  const [inventory, setInventory]               = useState([]);
  const [loadingInventory, setLoadingInventory] = useState(true);
  const [searchQuery, setSearchQuery]           = useState('');
  const [priorityFilter, setPriorityFilter]     = useState('all');
  const [categoryFilter, setCategoryFilter]     = useState('all');
  const [selectedKeys, setSelectedKeys]         = useState(() => new Set());

  useEffect(() => {
    let mounted = true;
    setLoadingInventory(true);
    (async () => {
      try {
        const [masterIds, invRes] = await Promise.all([
          fetchItemMasterProductIdSet(),
          inventoryAPI.getAll({ per_page: 2000 }),
        ]);
        if (!mounted) return;
        let rows = Array.isArray(invRes?.data) ? invRes.data : [];
        rows = rows.filter((row) => {
          const pid = Number(row.product_id ?? row.product?.product_id);
          return row.product && Number.isFinite(pid) && pid > 0 && masterIds.has(pid);
        });
        setInventory(rows);
      } catch (err) {
        console.error('PO Recommendation load failed:', err);
        if (mounted) setInventory([]);
      } finally {
        if (mounted) setLoadingInventory(false);
      }
    })();
    return () => { mounted = false; };
  }, []);

  /** Client-side sum fallback if stock_on_hand_total missing on nested product. */
  const qtyTotalByProductId = useMemo(() => {
    const m = new Map();
    inventory.forEach((row) => {
      const pid = row.product_id ?? row.product?.product_id;
      if (pid == null || pid === '') return;
      const q = Number(row.quantity_on_hand ?? 0);
      m.set(Number(pid), (m.get(Number(pid)) || 0) + q);
    });
    return m;
  }, [inventory]);

  const lowStockItems = useMemo(() => {
    return inventory.filter((item) => {
      const qoh       = warehouseStockOnHand(item, qtyTotalByProductId);
      const threshold = (item.reorder_level ?? 0) || (item.product?.recommended_stocks ?? 0);
      return threshold > 0 && qoh <= threshold;
    });
  }, [inventory, qtyTotalByProductId]);

  const counts = useMemo(() => ({
    ALL:         lowStockItems.length,
    CRITICAL:    lowStockItems.filter((i) => getPriority(i, warehouseStockOnHand(i, qtyTotalByProductId)) === 'CRITICAL').length,
    HIGH:        lowStockItems.filter((i) => getPriority(i, warehouseStockOnHand(i, qtyTotalByProductId)) === 'HIGH').length,
    MEDIUM:      lowStockItems.filter((i) => getPriority(i, warehouseStockOnHand(i, qtyTotalByProductId)) === 'MEDIUM').length,
    APPLIANCES:  lowStockItems.filter((i) => i.product?.product_type === 'appliance').length,
    CONSUMABLES: lowStockItems.filter((i) => i.product?.product_type === 'consumable').length,
  }), [lowStockItems, qtyTotalByProductId]);

  const filteredInventory = useMemo(() => {
    return lowStockItems.filter((item) => {
      const priority = getPriority(item, warehouseStockOnHand(item, qtyTotalByProductId));
      const categoryName = (item.product?.category?.category_name || '').trim();
      if (priorityFilter !== 'all' && priority.toLowerCase() !== priorityFilter) return false;
      if (categoryFilter !== 'all' && categoryName !== categoryFilter) return false;
      if (searchQuery.trim()) {
        const q     = searchQuery.toLowerCase();
        const name  = (item.product?.product_name              || '').toLowerCase();
        const code  = (item.product?.product_code              || '').toLowerCase();
        const cat   = (item.product?.category?.category_name   || '').toLowerCase();
        const brand = (item.product?.brand?.brand_name         || '').toLowerCase();
        if (!name.includes(q) && !code.includes(q) && !cat.includes(q) && !brand.includes(q)) return false;
      }
      return true;
    });
  }, [lowStockItems, searchQuery, priorityFilter, categoryFilter, qtyTotalByProductId]);

  const selectedCount = selectedKeys.size;

  const toggleRow = (inv) => {
    const key = String(inv.inventory_id ?? `${inv.product_id ?? inv.product?.product_id ?? ''}-${inv.location_id ?? ''}`);
    setSelectedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const selectAllFiltered = () => {
    setSelectedKeys(() => {
      const next = new Set();
      filteredInventory.forEach((inv) => {
        const key = String(inv.inventory_id ?? `${inv.product_id ?? inv.product?.product_id ?? ''}-${inv.location_id ?? ''}`);
        next.add(key);
      });
      return next;
    });
  };

  const clearSelection = () => setSelectedKeys(new Set());

  const openDraftWithSelection = () => {
    const selected = filteredInventory
      .filter((inv) => selectedKeys.has(String(inv.inventory_id ?? `${inv.product_id ?? inv.product?.product_id ?? ''}-${inv.location_id ?? ''}`)))
      .map((inv) => {
        const stock = warehouseStockOnHand(inv, qtyTotalByProductId);
        const threshold = (inv.reorder_level ?? 0) || (inv.product?.recommended_stocks ?? 0);
        const suggested = Math.max(0, threshold - stock);
        return {
          inventory_id: inv.inventory_id ?? null,
          location_id: inv.location_id ?? inv.location?.location_id ?? null,
          location_name: inv.location?.location_name ?? inv.location?.name ?? '',
          product_id: inv.product_id ?? inv.product?.product_id ?? null,
          product_code: inv.product?.product_code ?? '',
          product_name: inv.product?.product_name ?? '',
          category: inv.product?.category?.category_name ?? '',
          brand: inv.product?.brand?.brand_name ?? '',
          product_type: inv.product?.product_type ?? 'appliance',
          unit_cost: Number(inv.product?.cost_price || inv.product?.unit_price || 0),
          current_stock: Number(stock || 0),
          reorder_point: Number(threshold || 0),
          suggested_qty: Number(suggested || 0),
        };
      })
      // Drop zero suggestions so draft is clean
      .filter((x) => x.product_id && x.suggested_qty > 0);

    navigate('/admin/draft-po-creator', { state: { recommendedItems: selected } });
  };

  const recommendationCategories = useMemo(() => {
    const set = new Set();
    lowStockItems.forEach((item) => {
      const c = (item.product?.category?.category_name || '').trim();
      if (c) set.add(c);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [lowStockItems]);

  return (
    <AdminLayout>
      <div className="sre-page">
        <div className="sre-banner">
          <div className="sre-banner-left">
            <div className="sre-banner-icon-wrap">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" width="26" height="26">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
              </svg>
            </div>
            <div>
              <div className="sre-banner-title-row">
                <h1 className="sre-banner-title">PO Recommendation</h1>
                <span className="sre-banner-chip">VIEW ONLY</span>
              </div>
              <p className="sre-banner-subtitle">
                Suggested reorder quantities based on stock and reorder points.&nbsp;
                {counts.CRITICAL > 0 && (
                  <span className="sre-stat-chip sre-stat-critical">{counts.CRITICAL} CRITICAL</span>
                )}
                {counts.HIGH > 0 && (
                  <span className="sre-stat-chip sre-stat-high">{counts.HIGH} HIGH</span>
                )}
                {counts.MEDIUM > 0 && (
                  <span className="sre-stat-chip sre-stat-medium">{counts.MEDIUM} MEDIUM</span>
                )}
                &nbsp;— use Draft PO Creator to build and submit orders.
              </p>
            </div>
          </div>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <button type="button" className="sre-generate-btn" onClick={() => navigate('/admin/draft-po-creator')}>
              OPEN DRAFT PO CREATOR
            </button>
            <button
              type="button"
              className="sre-generate-btn"
              disabled={selectedCount === 0}
              onClick={openDraftWithSelection}
              title={selectedCount ? 'Create draft PO from selected items' : 'Select items first'}
            >
              CREATE DRAFT ({selectedCount})
            </button>
          </div>
        </div>

        <div className="sre-layout">
          <aside className="sre-sidebar">
            <div className="sre-filter-card">
              <h3>Filters &amp; Sorting</h3>
              <div className="sre-filter-field">
                <label>Search Item</label>
                <input
                  type="text"
                  className="sre-search-input"
                  placeholder="Filter items..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
              </div>
              <div className="sre-filter-field">
                <label>Priority Level</label>
                <select value={priorityFilter} onChange={(e) => setPriorityFilter(e.target.value)}>
                  <option value="all">All Level</option>
                  <option value="critical">Critical</option>
                  <option value="high">High</option>
                  <option value="medium">Medium</option>
                </select>
              </div>
              <div className="sre-filter-field">
                <label>Category</label>
                <select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
                  <option value="all">All Category</option>
                  {recommendationCategories.map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
              </div>
            </div>
            <div className="sre-rule-insight">
              <h4>Rule Insight</h4>
              <p>
                <strong>Item Master link:</strong> only products that exist in Item Master (Products catalog) appear here.
                Stock level uses the same on-hand totals as Item Master; each card is one inventory location row.
              </p>
            </div>
          </aside>

          <section className="sre-main-list">
            <div className="sre-list-head">
              <h3>Replenishment Targets</h3>
              <span>Showing {filteredInventory.length} identified shortages</span>
            </div>
            {filteredInventory.length > 0 && (
              <div style={{ display: 'flex', gap: 10, margin: '10px 0 12px' }}>
                <button type="button" className="sre-generate-btn" onClick={selectAllFiltered}>
                  SELECT ALL
                </button>
                <button type="button" className="sre-generate-btn" onClick={clearSelection}>
                  CLEAR
                </button>
              </div>
            )}
            {loadingInventory ? (
              <div className="sre-state-box">
                <div className="sre-spinner"></div>
                <p>Loading inventory data...</p>
              </div>
            ) : filteredInventory.length === 0 ? (
              <div className="sre-state-box sre-state-empty">
                <p>No replenishment targets found.</p>
              </div>
            ) : (
              <div className="sre-list-cards">
                {filteredInventory.map((item) => {
                  const stock      = warehouseStockOnHand(item, qtyTotalByProductId);
                  const priority   = getPriority(item, stock);
                  const cfg        = PRIORITY_CFG[priority];
                  const threshold  = (item.reorder_level ?? 0) || (item.product?.recommended_stocks ?? 0);
                  const suggested  = Math.max(0, threshold - stock);
                  const prodType   = item.product?.product_type === 'appliance' ? 'APPLIANCE' : 'PART';
                  const key        = String(item.inventory_id ?? `${item.product_id ?? item.product?.product_id ?? ''}-${item.location_id ?? ''}`);
                  const checked    = selectedKeys.has(key);
                  const locLabel =
                    (item.location?.location_name || item.location?.name || '').trim() || 'This location';
                  const rowBin     = Number(item.quantity_on_hand ?? 0);
                  const showBinNote = rowBin !== stock;

                  return (
                    <div
                      key={item.inventory_id}
                      className="sre-row-card sre-row-card--readonly"
                      style={{ '--priority-color': cfg.color }}
                      role="button"
                      tabIndex={0}
                      onClick={() => toggleRow(item)}
                      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') toggleRow(item); }}
                    >
                      <div className="sre-row-left">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => toggleRow(item)}
                          onClick={(e) => e.stopPropagation()}
                          style={{ marginRight: 10 }}
                          aria-label="Select row"
                        />
                        <span className="sre-priority-badge" style={{ color: cfg.color, background: cfg.bg, borderColor: cfg.border }}>
                          {cfg.label}
                        </span>
                        <h4>{item.product?.product_name || 'Unknown Product'}</h4>
                        <p>
                          <span style={{ display: 'block', color: '#64748b', fontSize: '0.85rem', marginBottom: 4 }}>
                            {locLabel}
                          </span>
                          Brand: {item.product?.brand?.brand_name || 'Generic'} · Unit Cost: ₱{Number(item.product?.cost_price || item.product?.unit_price || 0).toLocaleString()}
                        </p>
                      </div>
                      <div className="sre-row-mid">
                        <div>
                          <span>Stock Level</span>
                          <strong>{stock}</strong>
                          {showBinNote && (
                            <small style={{ display: 'block', opacity: 0.85 }}>
                              Location row: {rowBin} (differs from warehouse total above — check inventory records)
                            </small>
                          )}
                          <small style={{ display: 'block', marginTop: 4, opacity: 0.85 }}>/ {threshold || 0} ROP</small>
                        </div>
                        <span className={`sre-type-chip sre-type-chip--${item.product?.product_type || 'appliance'}`}>{prodType}</span>
                      </div>
                      <div className="sre-row-right">
                        <span>Suggested Qty</span>
                        <strong>+{suggested}</strong>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </section>
        </div>
      </div>
    </AdminLayout>
  );
};

export default PORecommendation;
