import React, { useEffect, useState, lazy, Suspense } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../../admin/components/AdminLayout';
import { useAuth } from '../../admin/context/AuthContext';
import { getRoleKey, ROLES } from '../../admin/utils/roles';
import { batchAPI, checkApiHealth, getApiBaseUrl } from '../../admin/services/api';
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
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [connectionError, setConnectionError] = useState(null);

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
        <div className="dashboard-loading">
          <p>Loading dashboard...</p>
        </div>
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
  const lowStockItems         = data?.low_stock_items           ?? [];
  const inventoryByCategory   = data?.inventory_by_category    ?? { labels: [], values: [] };
  const stockStatusByLocation = data?.stock_status_by_location  ?? { labels: [], inStock: [], lowStock: [] };
  const warehouseCapacity     = data?.warehouse_capacity        ?? { labels: [], capacity: [], occupancy: [] };
  const stockTrends           = data?.stock_trends              ?? { labels: [], values: [] };
  const transactionTrends     = data?.transaction_trends        ?? { labels: [], values: [] };
  const recentActivity        = data?.recent_activity           ?? [];

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
      {/* HEADER */}
      <div className="dashboard-header">
        <h1>System Dashboard</h1>
        <p>System-wide statistics and overall health monitoring.</p>
      </div>

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

      {/* APPROVAL ALERT PANEL */}
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
        <button type="button" className="approval-alert-btn" onClick={() => navigate('/admin/approval-queue')}>
          GO TO APPROVALS
        </button>
      </div>

      {/* LOW STOCK ALERT */}
      {lowStockItems.length > 0 && (
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

      {/* ENTERPRISE QUICK ACTIONS */}
      <div className="quick-actions-section">
        <div className="quick-actions-header">
          <div>
            <h3 className="quick-actions-title">ENTERPRISE QUICK ACTIONS</h3>
            <p className="quick-actions-subtitle">Direct access to newly integrated system modules.</p>
          </div>
          <button type="button" className="quick-actions-view-all" onClick={() => navigate('/admin/config')}>
            VIEW ALL INTEGRATIONS &gt;
          </button>
        </div>
        <div className="quick-actions-grid">
          {[
            { label: 'CREATE USER', path: '/admin/users', cls: 'red', icon: <><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></> },
            { label: 'LOCATIONS', path: '/admin/locations', cls: 'yellow', icon: <><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></> },

            { label: 'CUSTOMER', path: '/admin/customers', cls: 'green', icon: <><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></> },
            { label: 'CLAIM', path: '/admin/warranty', cls: 'purple', icon: <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/> },
            { label: 'TRANSFERS', path: '/admin/inventory', cls: 'grey', icon: <><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></> },
          ].map(({ label, path, cls, icon }) => (
            <button key={label} type="button" className="quick-action-card" onClick={() => navigate(path)}>
              <span className={`quick-action-icon quick-action-icon-${cls}`}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">{icon}</svg>
              </span>
              <span className="quick-action-label">{label}</span>
            </button>
          ))}
        </div>
      </div>

      {/* CHARTS ROW 1 + RECENT ACTIVITY */}
      <div className="charts-section">
        <div className="chart-card">
          <h3>Inventory by Category</h3>
          <div className="chart-wrapper">
            <Bar data={{ labels: inventoryByCategory.labels, datasets: [{ data: inventoryByCategory.values, backgroundColor: '#DC2626', borderRadius: 6, maxBarThickness: 72 }] }} options={barOptions} />
          </div>
        </div>

        <div className="recent-activity-section recent-activity-section--card">
          <div className="recent-activity-header">
            <div>
              <h3 className="recent-activity-title">Recent Activity</h3>
              <p className="recent-activity-subtitle">Latest sales, transfers, and receivings across all branches.</p>
            </div>
            <button type="button" className="recent-activity-view-all" onClick={() => navigate('/admin/inventory')}>
              VIEW ALL &gt;
            </button>
          </div>
          {recentActivity.length === 0 ? (
            <div className="recent-activity-empty">No recent activity found.</div>
          ) : (
            <ul className="recent-activity-list">
              {recentActivity.map((item, idx) => {
                const meta = activityMeta[item.type] ?? { label: item.type, color: '#6b7280', bg: '#f3f4f6' };
                return (
                  <li key={idx} className="recent-activity-item">
                    <span className="ra-dot" style={{ background: meta.color }} />
                    <div className="ra-body">
                      <div className="ra-top">
                        <span className="ra-label" style={{ color: meta.color, background: meta.bg }}>{meta.label}</span>
                        <span className="ra-name">{item.label}</span>
                        {item.location && <span className="ra-location">{item.location}</span>}
                      </div>
                      <p className="ra-desc">{item.description}</p>
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

      {/* CHARTS ROW 2 */}
      <div className="charts-section">
        <div className="chart-card">
          <h3>Stock Status by Branch</h3>
          <div className="chart-wrapper">
            <Bar data={{ labels: stockStatusByLocation.labels, datasets: [{ label: 'In Stock', data: stockStatusByLocation.inStock, backgroundColor: '#DC2626', borderRadius: 4, barThickness: 50 }, { label: 'Low Stock', data: stockStatusByLocation.lowStock, backgroundColor: '#F59E0B', borderRadius: 4, barThickness: 50 }] }} options={groupedBarOptions} />
          </div>
        </div>
        <div className="chart-card">
          <h3>Warehouse Capacity</h3>
          <div className="chart-wrapper">
            <Bar data={{ labels: warehouseCapacity.labels, datasets: [{ label: 'Capacity', data: warehouseCapacity.capacity, backgroundColor: '#D1D5DB', borderRadius: 4, barThickness: 80 }, { label: 'Occupancy', data: warehouseCapacity.occupancy, backgroundColor: '#DC2626', borderRadius: 4, barThickness: 80 }] }} options={groupedBarOptions} />
          </div>
        </div>
      </div>

      {/* CHARTS ROW 3 */}
      <div className="charts-section">
        <div className="chart-card">
          <h3>Stock Level Trends</h3>
          <div className="chart-wrapper">
            <Line data={{ labels: stockTrends.labels, datasets: [{ label: 'reorder-level', data: stockTrends.labels.map(() => 140), borderColor: '#F59E0B', backgroundColor: 'transparent', borderWidth: 2, borderDash: [8, 4], pointRadius: 0, pointHoverRadius: 0 }, { label: 'stock', data: stockTrends.values, borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.1)', fill: true, tension: 0.4, pointRadius: 5, pointHoverRadius: 7, pointBackgroundColor: '#DC2626', pointBorderColor: '#ffffff', pointBorderWidth: 2 }] }} options={lineOptions} />
          </div>
        </div>
        <div className="chart-card">
          <h3>Transaction Trends</h3>
          <div className="chart-wrapper">
            <Line data={{ labels: transactionTrends.labels, datasets: [{ label: 'purchase', data: transactionTrends.values.map((v) => v * 0.6), borderColor: '#F59E0B', backgroundColor: 'rgba(245,158,11,0.2)', fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5 }, { label: 'sales', data: transactionTrends.values, borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.2)', fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5 }] }} options={lineOptions} />
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
const ROLE_FALLBACK = <div className="dashboard-loading"><p>Loading...</p></div>;

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
