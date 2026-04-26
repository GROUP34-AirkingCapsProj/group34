import React, { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { useAuth } from '../context/AuthContext';
import { batchAPI, inventoryAPI } from '../services/api';
import {
  getApprovalQueuePurchaseOrders,
  getApprovalQueueRestockRequests,
} from '../utils/approvalNotifications';
import '../styles/role_dashboards.css';

import {
  Chart as ChartJS,
  CategoryScale, LinearScale, BarElement, LineElement,
  PointElement, Tooltip, Legend, Filler,
} from 'chart.js';
import { Bar, Line } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, PointElement, Tooltip, Legend, Filler);

const ACCENT = '#059669';
const ACCENT_LIGHT = 'rgba(5,150,105,0.12)';

const BranchManagerDashboard = () => {
  const navigate = useNavigate();
  const { user: _user } = useAuth();
  const user = _user || {};
  const locationName = user?.location?.location_name || user?.branch?.branch_name || 'My Branch';

  const [dashData, setDashData] = useState(null);
  const [invData, setInvData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [localPendingApprovals, setLocalPendingApprovals] = useState(0);

  const refreshLocalApprovalCount = useCallback(() => {
    const restock = getApprovalQueueRestockRequests().filter((r) => r.status === 'pending').length;
    const pos = getApprovalQueuePurchaseOrders().filter((po) => po.status === 'pending').length;
    setLocalPendingApprovals(restock + pos);
  }, []);

  useEffect(() => {
    refreshLocalApprovalCount();
    window.addEventListener('approval-notifications-updated', refreshLocalApprovalCount);
    window.addEventListener('storage', refreshLocalApprovalCount);
    return () => {
      window.removeEventListener('approval-notifications-updated', refreshLocalApprovalCount);
      window.removeEventListener('storage', refreshLocalApprovalCount);
    };
  }, [refreshLocalApprovalCount]);

  useEffect(() => {
    const load = async () => {
      try {
        const [dashRes, invRes] = await Promise.allSettled([
          batchAPI.get({ include: ['dashboard'] }),
          inventoryAPI.getAll({ per_page: 200 }),
        ]);
        if (dashRes.status === 'fulfilled') setDashData(dashRes.value?.data?.dashboard ?? null);
        if (invRes.status === 'fulfilled') {
          const items = invRes.value?.data ?? invRes.value?.inventory ?? [];
          setInvData(Array.isArray(items) ? items : []);
        }
      } catch (e) {
        setError(e.message || 'Failed to load dashboard.');
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: ACCENT }} /><p>Loading branch data...</p></div></AdminLayout>;
  if (error) return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = dashData?.stats ?? {};
  const stockTrends = dashData?.stock_trends ?? { labels: [], values: [] };
  const txTrends = dashData?.transaction_trends ?? { labels: [], values: [] };
  const warehouseCapacity = dashData?.warehouse_capacity ?? { labels: [], capacity: [], occupancy: [] };

  const lowStockItems = invData.filter(i => {
    const qoh = Number(i.quantity_on_hand ?? i.quantity ?? 0);
    const threshold = Number(i.reorder_level ?? i.recommended_stocks ?? 5);
    return qoh <= threshold;
  });

  const barOpts = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: true, position: 'bottom', labels: { color: '#6b7280', usePointStyle: true, pointStyle: 'rect', padding: 12, font: { size: 11 } } }, tooltip: { backgroundColor: '#1f2937', padding: 10, borderRadius: 8 } },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 11 } } },
      y: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280', font: { size: 11 } }, beginAtZero: true },
    },
  };

  const lineOpts = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: true, position: 'bottom', labels: { color: '#6b7280', usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } } }, tooltip: { backgroundColor: '#1f2937', padding: 10, borderRadius: 8 } },
    scales: {
      x: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280', font: { size: 11 } } },
      y: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280', font: { size: 11 } }, beginAtZero: true },
    },
    interaction: { intersect: false, mode: 'index' },
  };

  return (
    <AdminLayout>
      {/* HEADER */}
      <div className="rd-header" style={{ '--rd-accent': ACCENT }}>
        <div className="rd-header-left">
          <div className="rd-header-icon" style={{ background: ACCENT_LIGHT, color: ACCENT }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
          </div>
          <div>
            <div className="rd-title-row">
              <h1 className="rd-title">Branch Dashboard</h1>
              <span className="rd-location-chip" style={{ background: ACCENT_LIGHT, color: ACCENT }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="11" height="11">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                {locationName}
              </span>
            </div>
            <p className="rd-subtitle">Monitor branch-level operations, transactions, and inventory.</p>
          </div>
        </div>
        <button className="rd-primary-btn" style={{ background: ACCENT }} onClick={() => navigate('/admin/inventory')}>
          View Inventory
        </button>
      </div>

      {/* STATS */}
      <div className="rd-stats-grid">
        {[
          { label: 'Branch Inventory', value: invData.length, icon: 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z', color: ACCENT, bg: ACCENT_LIGHT },
          { label: 'Low Stock Alerts', value: lowStockItems.length, icon: 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01', color: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
          { label: 'Pending Actions', value: stats.pending_actions ?? 0, icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11', color: '#2563eb', bg: 'rgba(37,99,235,0.12)' },
          { label: 'Active Branches', value: stats.active_branches ?? 0, icon: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', color: '#7c3aed', bg: 'rgba(124,58,237,0.12)' },
        ].map(({ label, value, icon, color, bg }) => (
          <div key={label} className="rd-stat-card">
            <div className="rd-stat-icon" style={{ background: bg, color }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="20" height="20">
                <path d={icon} />
              </svg>
            </div>
            <div className="rd-stat-body">
              <span className="rd-stat-label">{label}</span>
              <span className="rd-stat-value" style={{ color }}>{value}</span>
            </div>
          </div>
        ))}
      </div>

      {localPendingApprovals > 0 && (
        <div className="approval-alert-panel" style={{ marginBottom: '1.25rem' }}>
          <div className="approval-alert-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              <path d="M9 12l2 2 4-4" />
            </svg>
          </div>
          <div className="approval-alert-content">
            <h3>Approval queue</h3>
            <p>
              {localPendingApprovals} item{localPendingApprovals === 1 ? '' : 's'} waiting for review. As branch manager, you can approve or deny here.
            </p>
          </div>
          <button
            type="button"
            className="approval-alert-btn"
            onClick={() => navigate('/admin/approval-queue')}
          >
            OPEN APPROVAL QUEUE
          </button>
        </div>
      )}

      {/* QUICK LINKS */}
      <div className="rd-quick-links">
        {[
          { label: 'Approval Queue', path: '/admin/approval-queue', color: '#b91c1c' },
          { label: 'Transfer Requests', path: '/admin/transfers', color: ACCENT },
          { label: 'Issue Inventory', path: '/admin/issuances', color: '#2563eb' },
          { label: 'Transactions', path: '/admin/transactions', color: '#d97706' },
          { label: 'Branch Reports', path: '/admin/reports', color: '#6b7280' },
        ].map(({ label, path, color }) => (
          <button key={label} className="rd-quick-link-btn" style={{ borderColor: color, color }} onClick={() => navigate(path)}>
            {label}
          </button>
        ))}
      </div>

      {/* CHARTS */}
      <div className="rd-charts-row">
        <div className="rd-chart-card">
          <div className="rd-chart-header"><h3>Stock Level Trends</h3></div>
          <div className="rd-chart-body">
            <Line
              data={{
                labels: stockTrends.labels,
                datasets: [
                  { label: 'Reorder Level', data: stockTrends.labels.map(() => 140), borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 2, borderDash: [6, 4], pointRadius: 0 },
                  { label: 'Stock', data: stockTrends.values, borderColor: ACCENT, backgroundColor: ACCENT_LIGHT, fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: ACCENT, pointBorderColor: '#fff', pointBorderWidth: 2 },
                ],
              }}
              options={lineOpts}
            />
          </div>
        </div>

        <div className="rd-chart-card">
          <div className="rd-chart-header"><h3>Warehouse Capacity</h3></div>
          <div className="rd-chart-body">
            <Bar
              data={{
                labels: warehouseCapacity.labels,
                datasets: [
                  { label: 'Capacity', data: warehouseCapacity.capacity, backgroundColor: '#d1d5db', borderRadius: 4, barThickness: 60 },
                  { label: 'Occupancy', data: warehouseCapacity.occupancy, backgroundColor: ACCENT, borderRadius: 4, barThickness: 60 },
                ],
              }}
              options={barOpts}
            />
          </div>
        </div>
      </div>

      {/* LOW STOCK TABLE */}
      {lowStockItems.length > 0 && (
        <div className="rd-table-card">
          <div className="rd-table-header">
            <h3>Low Stock Alerts</h3>
            <button className="rd-table-action" style={{ color: ACCENT }} onClick={() => navigate('/admin/inventory')}>
              View Full Inventory →
            </button>
          </div>
          <div className="rd-table-wrap">
            <table className="rd-table">
              <thead>
                <tr><th>Product</th><th>Location</th><th>On Hand</th><th>Reorder Level</th><th>Status</th></tr>
              </thead>
              <tbody>
                {lowStockItems.slice(0, 8).map((item, i) => {
                  const qoh = Number(item.quantity_on_hand ?? item.quantity ?? 0);
                  const level = Number(item.reorder_level ?? item.recommended_stocks ?? 5);
                  const isCritical = qoh === 0 || qoh < level * 0.25;
                  return (
                    <tr key={i}>
                      <td>{item.product?.product_name ?? item.item_name ?? '—'}</td>
                      <td>{item.location?.location_name ?? '—'}</td>
                      <td><span className={`rd-qty ${isCritical ? 'rd-qty-critical' : 'rd-qty-low'}`}>{qoh}</span></td>
                      <td>{level}</td>
                      <td><span className={`rd-badge ${isCritical ? 'rd-badge-critical' : 'rd-badge-low'}`}>{isCritical ? 'CRITICAL' : 'LOW'}</span></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </AdminLayout>
  );
};

export default BranchManagerDashboard;
