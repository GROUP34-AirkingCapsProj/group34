import React, { useEffect, useState, useRef, lazy, Suspense } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../../admin/components/AdminLayout';
import Loader from '../../admin/components/Loader';
import { useAuth } from '../../admin/context/AuthContext';
import { getRoleKey, ROLES } from '../../admin/utils/roles';
import { batchAPI, dashboardAPI, checkApiHealth, getApiBaseUrl } from '../../admin/services/api';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import { Bar, Line } from 'react-chartjs-2';
import '../../admin/styles/dashboard_air.css';
import DateInput from '../../admin/components/DateInput';

// Role-specific dashboards (lazy-loaded)
const InventoryAnalystDashboard = lazy(() => import('./InventoryAnalystDashboard'));
const AuditorDashboard = lazy(() => import('./AuditorDashboard'));
const BranchManagerDashboard = lazy(() => import('./BranchManagerDashboard'));
const WarehousePersonnelDashboard = lazy(() => import('./WarehousePersonnelDashboard'));

// REGISTER
ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  LineElement,
  PointElement,
  Tooltip,
  Legend,
  Filler
);

/* ─────────────────────────────────────────────
   Admin-only dashboard (hooks always called)
───────────────────────────────────────────── */
const AdminDashboard = () => {
  const navigate = useNavigate();
  const { can } = useAuth();
  const visibleWidgets = [
    can('purchase_order_creation', 'read') && 'po_summary',
    can('inventory_adjustments', 'read') && 'low_stock',
    can('inventory_transfers', 'read') && 'ist_queue',
    can('sales_recording', 'read') && 'sales_summary',
    can('delivery_receiving', 'read') && 'receiving_summary',
  ].filter(Boolean);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [connectionError, setConnectionError] = useState(null);

  // ── Chart filters ──
  const todayStr = () => new Date().toISOString().split('T')[0];
  const daysAgoStr = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().split('T')[0]; };

  const PERIODS = [
    { key: 'today',     label: 'Today' },
    { key: 'yesterday', label: 'Yesterday' },
    { key: '7d',        label: 'Last 7D' },
    { key: '30d',       label: 'Last 30D' },
    { key: '90d',       label: 'Last 90D' },
    { key: 'year',      label: 'This Year' },
  ];
  const PERIOD_RANGES = {
    today:     () => [todayStr(), todayStr()],
    yesterday: () => [daysAgoStr(1), daysAgoStr(1)],
    '7d':      () => [daysAgoStr(6), todayStr()],
    '30d':     () => [daysAgoStr(29), todayStr()],
    '90d':     () => [daysAgoStr(89), todayStr()],
    year:      () => [new Date().getFullYear() + '-01-01', todayStr()],
  };

  const [filterPeriod,    setFilterPeriod]    = useState('7d');
  const [filterDateFrom,  setFilterDateFrom]  = useState(daysAgoStr(6));
  const [filterDateTo,    setFilterDateTo]    = useState(todayStr());
  const [filterCatId,     setFilterCatId]     = useState('');
  const [filterBrandId,   setFilterBrandId]   = useState('');
  const [filterProductId, setFilterProductId] = useState('');
  const [filterOptions,   setFilterOptions]   = useState({ categories: [], brands: [], products: [] });
  const [chartData,       setChartData]       = useState(null);
  const [chartLoading,    setChartLoading]    = useState(false);
  const chartDebounce = useRef(null);

  const loadDashboard = async () => {
    setConnectionError(null);
    setLoading(true);
    try {
      const health = await checkApiHealth();
      if (!health.ok) {
        setConnectionError(health.message || 'Backend is not responding.');
        setLoading(false);
        return;
      }
      const res = await batchAPI.get({ include: ['dashboard'] });
      const dashboardData = res?.data?.dashboard ?? null;
      if (!dashboardData) {
        setConnectionError('Dashboard data was not returned. Please try again.');
        return;
      }
      setData(dashboardData);
    } catch (err) {
      const msg = err?.message || 'Connection error';
      const isAuth = err?.status === 401;
      setConnectionError(
        isAuth
          ? 'Session expired or not logged in. Please log in again.'
          : msg
      );
      if (isAuth) {
        setTimeout(() => navigate('/admin/login'), 2000);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadDashboard();
  }, []);

  // Load filter options once
  useEffect(() => {
    dashboardAPI.getFilterOptions().then(res => {
      const d = res?.data ?? {};
      setFilterOptions({
        categories: d.categories ?? [],
        brands:     d.brands     ?? [],
        products:   d.products   ?? [],
      });
    }).catch(() => {});
  }, []);

  // Re-fetch chart data whenever filters change (debounced 350ms)
  useEffect(() => {
    clearTimeout(chartDebounce.current);
    chartDebounce.current = setTimeout(async () => {
      setChartLoading(true);
      try {
        const params = { date_from: filterDateFrom, date_to: filterDateTo };
        if (filterCatId)     params.category_id = filterCatId;
        if (filterBrandId)   params.brand_id    = filterBrandId;
        if (filterProductId) params.product_id  = filterProductId;
        const res = await dashboardAPI.getCharts(params);
        setChartData(res?.data ?? null);
      } catch (_) {
        setChartData(null);
      } finally {
        setChartLoading(false);
      }
    }, 350);
    return () => clearTimeout(chartDebounce.current);
  }, [filterDateFrom, filterDateTo, filterCatId, filterBrandId, filterProductId]);

  const applyPeriod = (key) => {
    setFilterPeriod(key);
    const [from, to] = PERIOD_RANGES[key]();
    setFilterDateFrom(from);
    setFilterDateTo(to);
  };

  const clearChartFilters = () => {
    setFilterPeriod('7d');
    setFilterDateFrom(daysAgoStr(6));
    setFilterDateTo(todayStr());
    setFilterCatId('');
    setFilterBrandId('');
    setFilterProductId('');
  };

  if (connectionError) {
    return (
      <AdminLayout>
        <div className="dashboard-connection-error">
          <div className="dashboard-connection-error-card">
            <h2>Could not load dashboard</h2>
            <p className="dashboard-connection-error-message">{connectionError}</p>
            <p className="dashboard-connection-error-hint">
              Ensure the backend is running: <code>cd back-end && php artisan serve</code>
              <br />
              API base: <code>{getApiBaseUrl()}</code>
            </p>
            <p className="dashboard-connection-error-hint">
              If the backend runs on a different host or port, set <code>REACT_APP_API_URL</code> in the frontend <code>.env</code> (see <code>.env.example</code>), then restart the React dev server (<code>npm start</code>).
            </p>
            <button type="button" className="dashboard-connection-retry" onClick={loadDashboard}>
              Retry
            </button>
          </div>
        </div>
      </AdminLayout>
    );
  }

  if (loading || !data) {
    return (
      <AdminLayout>
        <Loader variant="page" label="Loading dashboard" />
      </AdminLayout>
    );
  }

  /* ── Chart options ── */
  const barOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1f2937', padding: 12, borderRadius: 8 } },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 11 } }, barPercentage: 0.5, categoryPercentage: 0.8, offset: true },
      y: { grid: { color: '#f3f4f6', drawBorder: false }, ticks: { color: '#6b7280', font: { size: 11 }, stepSize: 1 }, beginAtZero: true },
    },
    layout: { padding: { left: 8, right: 8 } },
  };

  const groupedBarOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom', align: 'center', labels: { color: '#6b7280', usePointStyle: true, pointStyle: 'rect', padding: 15, font: { size: 11 } } },
      tooltip: { backgroundColor: '#1f2937', padding: 12, borderRadius: 8 },
    },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } },
      y: { grid: { color: '#e5e7eb', drawBorder: false, borderDash: [3, 3] }, ticks: { color: '#6b7280', font: { size: 11 } }, beginAtZero: true },
    },
  };

  const lineOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom', align: 'center', labels: { color: '#6b7280', usePointStyle: true, pointStyle: 'circle', padding: 15, font: { size: 11 } } },
      tooltip: { backgroundColor: '#1f2937', padding: 12, borderRadius: 8 },
    },
    scales: {
      x: { grid: { color: '#f3f4f6', drawBorder: false }, ticks: { color: '#6b7280', font: { size: 11 } } },
      y: { grid: { color: '#f3f4f6', drawBorder: false }, ticks: { color: '#6b7280', font: { size: 11 }, stepSize: 45 }, beginAtZero: true },
    },
    interaction: { intersect: false, mode: 'index' },
  };

  const stats = data?.stats ?? {};
  const pendingActions        = stats.pending_actions ?? 0;
  const lowStockItems         = data?.low_stock_items ?? [];
  const recentActivity        = data?.recent_activity ?? [];
  // Charts use filtered data when available, otherwise the initial batch data
  const cd = chartData;
  const inventoryByCategory   = cd?.inventory_by_category    ?? data?.inventory_by_category    ?? { labels: [], values: [] };
  const stockStatusByLocation = cd?.stock_status_by_location ?? data?.stock_status_by_location ?? { labels: [], inStock: [], lowStock: [] };
  const warehouseCapacity     = cd?.warehouse_capacity       ?? data?.warehouse_capacity       ?? { labels: [], capacity: [], occupancy: [] };
  const stockTrends           = cd?.stock_trends             ?? data?.stock_trends             ?? { labels: [], values: [], reorder_level: 0 };
  const transactionTrends     = cd?.transaction_trends       ?? data?.transaction_trends       ?? { labels: [], values: [], purchase_values: [] };

  const timeAgo = (iso) => {
    if (!iso) return '';
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
  };

  const activityMeta = {
    sale:      { label: 'Sale',      color: '#DC2626', bg: 'rgba(220,38,38,0.08)'  },
    transfer:  { label: 'Transfer',  color: '#2563EB', bg: 'rgba(37,99,235,0.08)'  },
    receiving: { label: 'Receiving', color: '#059669', bg: 'rgba(5,150,105,0.08)'  },
    activity:  { label: 'Activity',  color: '#7C3AED', bg: 'rgba(124,58,237,0.08)' },
    audit:     { label: 'Audit',     color: '#D97706', bg: 'rgba(217,119,6,0.08)'  },
  };

  return (
    <AdminLayout>
     <div className="ak-page">
      {/* HEADER */}
      <header className="ak-header">
        <div className="ak-header-row">
          <div>
            <p className="ak-eyebrow">Overview · System</p>
            <h1 className="ak-title">System Dashboard</h1>
            <p className="ak-sub">System-wide statistics and overall health monitoring.</p>
          </div>
        </div>
      </header>

      {/* STATS */}
      <div className="stats-container">
        <div className="stat-card">
          <div className="stat-icon purple">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
          </div>
          <div className="stat-content"><h4>Total Users</h4><h2>{stats.total_users ?? 0}</h2></div>
        </div>
        <div className="stat-card">
          <div className="stat-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
              <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
          </div>
          <div className="stat-content"><h4>Active Branches</h4><h2>{stats.active_branches ?? 0}</h2></div>
        </div>
        <div className="stat-card">
          <div className="stat-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
            </svg>
          </div>
          <div className="stat-content"><h4>Total Products</h4><h2>{stats.total_items ?? 0}</h2></div>
        </div>
        <div className="stat-card">
          <div className="stat-icon orange">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
          </div>
          <div className="stat-content"><h4>Pending Actions</h4><h2>{pendingActions}</h2></div>
        </div>
      </div>

      {/* APPROVAL ALERT PANEL — gated by po_status_tracking.read */}
      {visibleWidgets.includes('po_summary') && (
        <div className="approval-alert-panel">
          <div className="approval-alert-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
              <path d="M9 12l2 2 4-4"></path>
            </svg>
          </div>
          <div className="approval-alert-content">
            <h3>Pending Approvals Queue</h3>
            <p>There are {pendingActions} items awaiting your authorization.</p>
          </div>
          <button type="button" className="approval-alert-btn" onClick={() => navigate('/admin/draft-po-creator')}>
            GO TO APPROVALS
          </button>
        </div>
      )}

      {/* LOW STOCK ALERT — gated by automated_inventory_monitoring.read */}
      {visibleWidgets.includes('low_stock') && lowStockItems.length > 0 && (
        <div className="low-stock-section">
          <div className="low-stock-header">
            <div className="low-stock-header-left">
              <div className="low-stock-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                  <line x1="12" y1="9" x2="12" y2="13"></line>
                  <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
              </div>
              <div>
                <h3 className="low-stock-title">Low Stock Alert</h3>
                <p className="low-stock-subtitle">{lowStockItems.length} product{lowStockItems.length !== 1 ? 's' : ''} at or below reorder level</p>
              </div>
            </div>
            <button type="button" className="low-stock-view-all" onClick={() => navigate('/admin/alerts')}>
              VIEW ALL ALERTS &gt;
            </button>
          </div>
          <div className="low-stock-table-wrap">
            <table className="low-stock-table">
              <thead>
                <tr>
                  <th>PRODUCT</th>
                  <th>CATEGORY</th>
                  <th>ON HAND</th>
                  <th>REORDER LEVEL</th>
                  <th>DEFICIENCY</th>
                  <th>STATUS</th>
                </tr>
              </thead>
              <tbody>
                {lowStockItems.map((item) => (
                  <tr key={item.product_id}>
                    <td className="ls-td-name">{item.product_name}</td>
                    <td className="ls-td-cat">{item.category ?? '—'}</td>
                    <td className="ls-td-num ls-td-qty">{item.quantity}</td>
                    <td className="ls-td-num">{item.recommended_stocks}</td>
                    <td className="ls-td-num ls-td-def">−{item.deficiency}</td>
                    <td>
                      <span className={`ls-badge ${item.quantity === 0 ? 'ls-badge--out' : 'ls-badge--low'}`}>
                        {item.quantity === 0 ? 'Out of Stock' : 'Needs Reorder'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
      

      {/* QUICK ACTIONS + RECENT ACTIVITY */}
      <div className="charts-section">
        {/* Quick Actions */}
        <div className="chart-card">
          <div className="quick-actions-header">
            <div>
              <p className="quick-actions-title">ENTERPRISE QUICK ACTIONS</p>
              <p className="quick-actions-subtitle">Direct access to newly integrated system modules.</p>
            </div>
            <button type="button" className="quick-actions-view-all" onClick={() => navigate('/admin/config')}>
              VIEW ALL &gt;
            </button>
          </div>
          <div className="quick-actions-grid quick-actions-grid--compact">
            {[
              { label: 'LOCATIONS', path: '/admin/locations', cls: 'yellow', icon: <><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></> },
              { label: 'CUSTOMER', path: '/admin/customers', cls: 'green', icon: <><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></> },
              { label: 'CLAIM', path: '/admin/warranty', cls: 'purple', icon: <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/> },
              { label: 'CREATE PR', path: '/admin/purchase-requests', state: { tab: 'create' }, cls: 'grey', icon: <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></> },
              { label: 'SCAN BARCODE', path: '/admin/inventory-operation', cls: 'blue', icon: <><path d="M4 7V5a2 2 0 0 1 2-2h2"/><path d="M16 3h2a2 2 0 0 1 2 2v2"/><path d="M4 17v2a2 2 0 0 0 2 2h2"/><path d="M16 21h2a2 2 0 0 0 2-2v-2"/><line x1="8" y1="8" x2="8" y2="16"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="16" y1="8" x2="16" y2="16"/></> },
            ].map(({ label, path, state, cls, icon }) => (
              <button key={label} type="button" className="quick-action-card" onClick={() => navigate(path, state ? { state } : undefined)}>
                <span className={`quick-action-icon quick-action-icon-${cls}`}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">{icon}</svg>
                </span>
                <span className="quick-action-label">{label}</span>
              </button>
            ))}
          </div>
        </div>

        {/* Recent Activity */}
        <div className="recent-activity-section recent-activity-section--card">
          <div className="recent-activity-header">
            <div>
              <h3 className="recent-activity-title">Recent Activity</h3>
              <p className="recent-activity-subtitle">Latest sales, transfers, receivings, and system changes.</p>
            </div>
            <button type="button" className="recent-activity-view-all" onClick={() => navigate('/admin/audit')}>
              VIEW ALL &gt;
            </button>
          </div>
          {recentActivity.length === 0 ? (
            <div className="recent-activity-empty">No recent activity found.</div>
          ) : (
            <ul className="recent-activity-list">
              {recentActivity.map((item, idx) => {
                const meta = activityMeta[item.type] ?? { label: item.type, color: '#6b7280', bg: '#f3f4f6' };
                const isAudit = item.type === 'audit';
                return (
                  <li key={idx} className="recent-activity-item">
                    <span className="ra-dot" style={{ background: meta.color }} />
                    <div className="ra-body">
                      <div className="ra-top">
                        <span className="ra-label" style={{ color: meta.color, background: meta.bg }}>{meta.label}</span>
                        <span className="ra-name">{item.label}</span>
                        {item.location && <span className="ra-location">{item.location}</span>}
                      </div>
                      <p className={`ra-desc${isAudit ? ' ra-desc--wrap' : ''}`}>{item.description}</p>
                      {item.user && <p className="ra-user">by {item.user}</p>}
                    </div>
                    <div className="ra-right">
                      <span className={`ra-status ra-status--${item.status?.toLowerCase().replace(/\s+/g, '-')}`}>{item.status}</span>
                      <span className="ra-time">{timeAgo(item.timestamp)}</span>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>

      {/* CHART FILTERS */}
      <div className="dash-filter-bar">
        <div className="dash-filter-row">
          <div className="dash-filter-group">
            <span className="dash-filter-label">Period</span>
            <div className="dash-period-btns">
              {PERIODS.map(({ key, label }) => (
                <button
                  key={key}
                  type="button"
                  className={`dash-period-btn${filterPeriod === key ? ' dash-period-btn--active' : ''}`}
                  onClick={() => applyPeriod(key)}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
          <div className="dash-filter-group">
            <span className="dash-filter-label">Date Range</span>
            <div className="dash-filter-dates">
              <DateInput
                className="dash-filter-date"
                value={filterDateFrom}
                max={filterDateTo}
                onChange={e => { setFilterDateFrom(e.target.value); setFilterPeriod('custom'); }}
              />
              <span className="dash-filter-date-sep">—</span>
              <DateInput
                className="dash-filter-date"
                value={filterDateTo}
                min={filterDateFrom}
                onChange={e => { setFilterDateTo(e.target.value); setFilterPeriod('custom'); }}
              />
            </div>
          </div>
        </div>
        <div className="dash-filter-row">
          <div className="dash-filter-group">
            <span className="dash-filter-label">Filter By</span>
            <div className="dash-filter-dims">
              <select
                className="dash-filter-select"
                value={filterCatId}
                onChange={e => { setFilterCatId(e.target.value); setFilterProductId(''); }}
              >
                <option value="">All Categories</option>
                {filterOptions.categories.map(c => (
                  <option key={c.category_id} value={c.category_id}>{c.category_name}</option>
                ))}
              </select>
              <select
                className="dash-filter-select"
                value={filterBrandId}
                onChange={e => setFilterBrandId(e.target.value)}
              >
                <option value="">All Brands</option>
                {filterOptions.brands.map(b => (
                  <option key={b.brand_id} value={b.brand_id}>{b.brand_name}</option>
                ))}
              </select>
              <select
                className="dash-filter-select"
                value={filterProductId}
                onChange={e => setFilterProductId(e.target.value)}
              >
                <option value="">All Products</option>
                {filterOptions.products.map(p => (
                  <option key={p.product_id} value={p.product_id}>{p.product_name}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="dash-filter-actions">
            {chartLoading && <span className="dash-filter-updating">Updating...</span>}
            <button type="button" className="dash-filter-clear-btn" onClick={clearChartFilters}>
              Clear Filters
            </button>
          </div>
        </div>
      </div>

      {/* TRANSACTION TRENDS — gated by sales_recording.read */}
      {visibleWidgets.includes('sales_summary') && (
        <div className="charts-section charts-section--single">
          <div className="chart-card">
            <h3>Transaction Trends</h3>
            <div className="chart-wrapper">
              <Line data={{ labels: transactionTrends.labels, datasets: [{ label: 'Purchase Orders', data: transactionTrends.purchase_values ?? [], borderColor: '#F59E0B', backgroundColor: 'rgba(245,158,11,0.2)', fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5 }, { label: 'Issue Outs', data: transactionTrends.values ?? [], borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.2)', fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5 }] }} options={lineOptions} />
            </div>
          </div>
        </div>
      )}
      

      {/* CHARTS ROW 2 */}
      <div className="charts-section">
        <div className="chart-card">
          <h3>Stock Status by Branch</h3>
          <div className="chart-wrapper">
            <Bar data={{ labels: stockStatusByLocation.labels, datasets: [{ label: 'In Stock', data: stockStatusByLocation.inStock, backgroundColor: '#DC2626', borderRadius: 4, barThickness: 50 }, { label: 'Low Stock', data: stockStatusByLocation.lowStock, backgroundColor: '#F59E0B', borderRadius: 4, barThickness: 50 }] }} options={groupedBarOptions} />
          </div>
        </div>

                <div className="chart-card">
          <h3>Inventory by Category</h3>
          <div className="chart-wrapper">
            <Bar data={{ labels: inventoryByCategory.labels, datasets: [{ data: inventoryByCategory.values, backgroundColor: '#DC2626', borderRadius: 6, maxBarThickness: 72 }] }} options={barOptions} />
          </div>
        </div>
      </div>

      {/* CHARTS ROW 3 */}
      <div className="charts-section">
        <div className="chart-card">
          <h3>Stock Level Trends</h3>
          <div className="chart-wrapper">
            <Line data={{ labels: stockTrends.labels, datasets: [{ label: 'Reorder Level', data: stockTrends.labels.map(() => stockTrends.reorder_level ?? 0), borderColor: '#F59E0B', backgroundColor: 'transparent', borderWidth: 2, borderDash: [8, 4], pointRadius: 0, pointHoverRadius: 0 }, { label: 'Avg Stock', data: stockTrends.values, borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.1)', fill: true, tension: 0.4, pointRadius: 5, pointHoverRadius: 7, pointBackgroundColor: '#DC2626', pointBorderColor: '#ffffff', pointBorderWidth: 2 }] }} options={lineOptions} />
          </div>
        </div>

                <div className="chart-card">
          <h3>Warehouse Capacity</h3>
          <div className="chart-wrapper">
            <Bar data={{ labels: warehouseCapacity.labels, datasets: [{ label: 'Capacity', data: warehouseCapacity.capacity, backgroundColor: '#D1D5DB', borderRadius: 4, barThickness: 80 }, { label: 'Occupancy', data: warehouseCapacity.occupancy, backgroundColor: '#DC2626', borderRadius: 4, barThickness: 80 }] }} options={groupedBarOptions} />
          </div>
        </div>
      </div>
     </div>
    </AdminLayout>

  );
};

/* ─────────────────────────────────────────────
   Dashboard — role-based router (no hooks here
   that would break if called after an early return)
───────────────────────────────────────────── */
const ROLE_FALLBACK = <Loader variant="page" />;

const Dashboard = () => {
  const { user: _user } = useAuth();
  const roleKey = getRoleKey(_user || {});

  if (roleKey === ROLES.INVENTORY_ANALYST) {
    return <Suspense fallback={ROLE_FALLBACK}><InventoryAnalystDashboard /></Suspense>;
  }
  if (roleKey === ROLES.AUDITOR) {
    return <Suspense fallback={ROLE_FALLBACK}><AuditorDashboard /></Suspense>;
  }
  if (roleKey === ROLES.BRANCH_MANAGER) {
    return <Suspense fallback={ROLE_FALLBACK}><BranchManagerDashboard /></Suspense>;
  }
  if (roleKey === ROLES.WAREHOUSE_PERSONNEL) {
    return <Suspense fallback={ROLE_FALLBACK}><WarehousePersonnelDashboard /></Suspense>;
  }

  return <AdminDashboard />;
};

export default Dashboard;
