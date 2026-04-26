import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { useAuth } from '../context/AuthContext';
import { batchAPI, inventoryAPI } from '../services/api';
import '../styles/role_dashboards.css';

const ACCENT = '#2563eb';
const ACCENT_LIGHT = 'rgba(37,99,235,0.12)';

const WarehousePersonnelDashboard = () => {
  const navigate = useNavigate();
  const { user: _user } = useAuth();
  const user = _user || {};
  const locationName = user?.location?.location_name || user?.branch?.branch_name || 'My Warehouse';

  const [dashData, setDashData] = useState(null);
  const [invData, setInvData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

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

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: ACCENT }} /><p>Loading warehouse data...</p></div></AdminLayout>;
  if (error) return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = dashData?.stats ?? {};

  const lowStockItems = invData.filter(i => {
    const qoh = Number(i.quantity_on_hand ?? i.quantity ?? 0);
    const threshold = Number(i.reorder_level ?? i.recommended_stocks ?? 5);
    return qoh <= threshold;
  });
  const outOfStock = invData.filter(i => Number(i.quantity_on_hand ?? i.quantity ?? 0) === 0).length;
  const totalItems = invData.length;

  // Quick action definitions
  const quickActions = [
    {
      label: 'Inventory Operation',
      desc: 'Scan items for quick lookup',
      path: '/admin/inventory-operation',
      icon: 'M3 3h4v4H3zM17 3h4v4h-4zM3 17h4v4H3zM7 7h2v2H7zM15 7h2v2h-2zM11 3h2v4h-2zM3 11h4v2H3zM17 11h4v2h-4zM11 11h2v2h-2zM9 13h2v2H9zM13 13h2v2h-2zM11 17h2v4h-2zM15 15h4v4h-4zM7 15h2v6H7z',
      color: ACCENT,
      bg: ACCENT_LIGHT,
    },
    {
      label: 'Receive Inventory',
      desc: 'Process incoming stock deliveries',
      path: '/admin/receivings',
      icon: 'M5 12h14M12 5l7 7-7 7',
      color: '#059669',
      bg: 'rgba(5,150,105,0.12)',
    },
    {
      label: 'Issue Inventory',
      desc: 'Release stock for internal use',
      path: '/admin/issuances',
      icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
      color: '#7c3aed',
      bg: 'rgba(124,58,237,0.12)',
    },
    {
      label: 'Process Transfer',
      desc: 'Handle stock transfers between locations',
      path: '/admin/transfers',
      icon: 'M17 3l4 4-4 4M3 7h18M7 21l-4-4 4-4M21 17H3',
      color: '#d97706',
      bg: 'rgba(217,119,6,0.12)',
    },
    {
      label: 'Assigned Inventory',
      desc: 'View your location\'s stock',
      path: '/admin/inventory',
      icon: 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z',
      color: '#64748b',
      bg: 'rgba(100,116,139,0.12)',
    },
  ];

  return (
    <AdminLayout>
      {/* HEADER */}
      <div className="rd-header" style={{ '--rd-accent': ACCENT }}>
        <div className="rd-header-left">
          <div className="rd-header-icon" style={{ background: ACCENT_LIGHT, color: ACCENT }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
          </div>
          <div>
            <div className="rd-title-row">
              <h1 className="rd-title">Warehouse Operations</h1>
              <span className="rd-location-chip" style={{ background: ACCENT_LIGHT, color: ACCENT }}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="11" height="11">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                {locationName}
              </span>
            </div>
            <p className="rd-subtitle">Daily tasks, receiving, transfers, and assigned inventory at a glance.</p>
          </div>
        </div>
        <button className="rd-primary-btn" style={{ background: ACCENT }} onClick={() => navigate('/admin/inventory-operation')}>
          Inventory Operation
        </button>
      </div>

      {/* STATS */}
      <div className="rd-stats-grid">
        {[
          { label: 'Assigned Items', value: totalItems, icon: 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z', color: ACCENT, bg: ACCENT_LIGHT },
          { label: 'Low Stock Alerts', value: lowStockItems.length, icon: 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01', color: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
          { label: 'Out of Stock', value: outOfStock, icon: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z', color: '#ef4444', bg: 'rgba(239,68,68,0.12)' },
          { label: 'Pending Actions', value: stats.pending_actions ?? 0, icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11', color: '#059669', bg: 'rgba(5,150,105,0.12)' },
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

      {/* QUICK ACTIONS GRID */}
      <div className="rd-section-title">Quick Actions</div>
      <div className="rd-actions-grid">
        {quickActions.map(({ label, desc, path, icon, color, bg }) => (
          <button key={label} className="rd-action-card" onClick={() => navigate(path)}>
            <div className="rd-action-icon" style={{ background: bg, color }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="26" height="26">
                <path d={icon} />
              </svg>
            </div>
            <span className="rd-action-label">{label}</span>
            <span className="rd-action-desc">{desc}</span>
            <span className="rd-action-arrow" style={{ color }}>→</span>
          </button>
        ))}
      </div>

      {/* LOW STOCK TABLE */}
      {lowStockItems.length > 0 && (
        <div className="rd-table-card">
          <div className="rd-table-header">
            <h3>Items Needing Attention</h3>
            <button className="rd-table-action" style={{ color: ACCENT }} onClick={() => navigate('/admin/inventory')}>
              View All →
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

export default WarehousePersonnelDashboard;
