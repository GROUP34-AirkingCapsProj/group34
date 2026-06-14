import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { useAuth } from '../context/AuthContext';
import { batchAPI } from '../services/api';
import '../styles/role_dashboards.css';

const ACCENT = '#d97706';
const ACCENT_LIGHT = 'rgba(217,119,6,0.12)';

const AuditorDashboard = () => {
  const navigate = useNavigate();
  const { can } = useAuth();
  const [dashData, setDashData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const load = async () => {
      try {
        const res = await batchAPI.get({ include: ['dashboard'] });
        setDashData(res?.data?.dashboard ?? null);
      } catch (e) {
        setError(e.message || 'Failed to load dashboard.');
      } finally {
        setLoading(false);
      }
    };
    load();
  }, []);

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: ACCENT }} /><p>Loading audit data...</p></div></AdminLayout>;
  if (error) return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = dashData?.stats ?? {};

  const auditRows = [];

  const typeStyle = {
    create:  { bg: 'rgba(5,150,105,0.12)', color: '#059669', label: 'CREATE' },
    update:  { bg: ACCENT_LIGHT, color: ACCENT, label: 'UPDATE' },
    delete:  { bg: 'rgba(239,68,68,0.12)', color: '#ef4444', label: 'DELETE' },
    alert:   { bg: 'rgba(245,158,11,0.12)', color: '#f59e0b', label: 'ALERT' },
    approve: { bg: 'rgba(37,99,235,0.12)', color: '#2563eb', label: 'APPROVE' },
  };

  return (
    <AdminLayout>
      {/* HEADER */}
      <div className="rd-header" style={{ '--rd-accent': ACCENT }}>
        <div className="rd-header-left">
          <div className="rd-header-icon" style={{ background: ACCENT_LIGHT, color: ACCENT }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
              <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
          </div>
          <div>
            <h1 className="rd-title">Audit Dashboard</h1>
            <p className="rd-subtitle">System audit logs, user activity, and compliance monitoring.</p>
          </div>
        </div>
        <button className="rd-primary-btn" style={{ background: ACCENT }} onClick={() => navigate('/admin/audit')}>
          Full Audit Trail
        </button>
      </div>

      {/* STATS */}
      <div className="rd-stats-grid">
        {[
          { label: 'Total Users', value: stats.total_users ?? 0, icon: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 8 0 4 4 0 0 0-8 0M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75', color: ACCENT, bg: ACCENT_LIGHT },
          { label: 'Active Branches', value: stats.active_branches ?? 0, icon: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10', color: '#059669', bg: 'rgba(5,150,105,0.12)' },
          { label: 'Total Products', value: stats.total_items ?? 0, icon: 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z', color: '#2563eb', bg: 'rgba(37,99,235,0.12)' },
          { label: 'Pending Actions', value: stats.pending_actions ?? 0, icon: 'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0', color: '#ef4444', bg: 'rgba(239,68,68,0.12)' },
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

      {/* MODULE ACCESS CARDS */}
      <div className="rd-module-grid">
        {[
          { label: 'Audit Trail', desc: 'View full system activity log', path: '/admin/audit', icon: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4', color: ACCENT, perm: ['audit_trail_viewing', 'read'] },
          { label: 'Transaction Logs', desc: 'Review all sales & purchase records', path: '/admin/transactions', icon: 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6', color: '#2563eb', perm: ['sales_recording', 'read'] },
          { label: 'Inventory Reports', desc: 'Stock levels across all locations', path: '/admin/inventory', icon: 'M22 12h-4l-3 9L9 3l-3 9H2', color: '#7c3aed' },
          { label: 'System Reports', desc: 'Export and download report data', path: '/admin/reports', icon: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6', color: '#059669' },
        ].filter(card => !card.perm || can(card.perm[0], card.perm[1])).map(({ label, desc, path, icon, color }) => (
          <button key={label} className="rd-module-card" onClick={() => navigate(path)}>
            <div className="rd-module-icon" style={{ background: `${color}18`, color }}>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="24" height="24">
                <path d={icon} />
              </svg>
            </div>
            <div className="rd-module-body">
              <span className="rd-module-label">{label}</span>
              <span className="rd-module-desc">{desc}</span>
            </div>
            <svg className="rd-module-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
              <path d="m9 18 6-6-6-6"/>
            </svg>
          </button>
        ))}
      </div>

      {/* RECENT AUDIT LOG */}
      <div className="rd-table-card">
        <div className="rd-table-header">
          <h3>Recent Activity Log</h3>
          <button className="rd-table-action" style={{ color: ACCENT }} onClick={() => navigate('/admin/audit')}>
            View All Logs →
          </button>
        </div>
        <div className="rd-table-wrap">
          <table className="rd-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Action</th>
                <th>Module</th>
                <th>Time</th>
                <th>Type</th>
              </tr>
            </thead>
            <tbody>
              {auditRows.map((row, i) => {
                const ts = typeStyle[row.type] || typeStyle.update;
                return (
                  <tr key={i}>
                    <td><span className="rd-user-cell">{row.user}</span></td>
                    <td>{row.action}</td>
                    <td><span className="rd-module-pill">{row.module}</span></td>
                    <td className="rd-time-cell">{row.time}</td>
                    <td><span className="rd-badge" style={{ background: ts.bg, color: ts.color }}>{ts.label}</span></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </AdminLayout>
  );
};

export default AuditorDashboard;
