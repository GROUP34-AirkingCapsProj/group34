import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { Line } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler } from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import { purchaseOrdersAPI } from '../services/api';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler);

const TYPE_TONE = { sale: 'green', transfer: 'blue', receiving: 'amber', adjustment: 'violet', default: 'gray' };

export default function AuditorDashboard() {
  const navigate = useNavigate();
  const { data, loading, error } = useRoleDashboard();
  const [pendingPos, setPendingPos] = useState(0);

  useEffect(() => {
    purchaseOrdersAPI.getAll({ verification_status: 'pending', per_page: 1 })
      .then((r) => setPendingPos(r?.pagination?.total ?? (Array.isArray(r?.data) ? r.data.length : 0)))
      .catch(() => {});
  }, []);

  if (loading) return <AdminLayout><Loader variant="page" label="Loading audit data" /></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const tt = data?.transaction_trends ?? { labels: [], values: [], purchase_values: [] };
  const txn30 = (tt.values || []).reduce((a, b) => a + Number(b || 0), 0) + (tt.purchase_values || []).reduce((a, b) => a + Number(b || 0), 0);
  const activity = data?.recent_activity ?? [];

  const trendData = {
    labels: tt.labels,
    datasets: [{ label: 'Transactions', data: (tt.values || []).map((v, i) => Number(v || 0) + Number((tt.purchase_values || [])[i] || 0)), borderColor: PALETTE.amber, backgroundColor: 'rgba(217,119,6,0.12)', fill: true, tension: 0.35, pointRadius: 2 }],
  };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Audit &amp; Oversight · All Locations</p>
              <h1 className="ak-title">Auditor Dashboard</h1>
              <p className="ak-sub">System-wide activity and control checkpoints (read-only).</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/audit-evidence')}>Audit &amp; evidence</button>
              {/* /audit, not /audit-trail — the latter is not a
                  registered route and this button did nothing. */}
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/audit')}>Audit trail</button>
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/compliance')}>Compliance</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="Transactions (30d)" value={fmt(txn30)} tone="amber" icon={<Activity />} />
          <Kpi label="Pending approvals" value={fmt(stats.pending_actions)} tone="crimson" icon={<Clock />} />
          <Kpi label="Pending registrations" value={fmt(stats.pending_registrations)} tone="blue" icon={<Tag />} />
          <Kpi label="Active locations" value={fmt(stats.active_locations)} tone="green" icon={<Pin />} />
          <Kpi label="POs awaiting verification" value={fmt(pendingPos)} tone="blue" icon={<Clock />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Activity trend</h2><p className="ak-card-meta">Transactions over the last 7 days</p></div></div>
          <div style={{ height: 280 }}>
            {tt.labels?.length ? <Line data={trendData} options={baseChartOptions} /> : <div className="ak-state">No activity in this period.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Recent activity</h2><p className="ak-card-meta">Latest sales, transfers and receivings</p></div></div>
          {activity.length === 0 ? (
            <div className="ak-state">No recent activity recorded.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Type</th><th>Event</th><th>Detail</th><th>By</th><th>Status</th></tr></thead>
                <tbody>
                  {activity.map((a, i) => (
                    <tr key={i}>
                      <td><span className={`ak-badge ak-badge--${TYPE_TONE[a.type] || TYPE_TONE.default}`}>{a.type}</span></td>
                      <td>{a.label}</td>
                      <td className="ak-muted">{a.description}</td>
                      <td className="ak-muted">{a.user || '—'}</td>
                      <td className="ak-muted">{a.status || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </AdminLayout>
  );
}

const Activity = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>);
const Clock = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>);
const Tag = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>);
const Pin = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>);
