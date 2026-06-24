import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler,
} from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler);

const qtyOf = (i) => Number(i.quantity_on_hand ?? i.quantity ?? 0);

export default function BranchManagerDashboard() {
  const navigate = useNavigate();
  const { locationName, data, inv, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><Loader variant="page" label="Loading branch data" /></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const skus = inv.filter((i) => qtyOf(i) > 0).length;
  const units = inv.reduce((s, i) => s + qtyOf(i), 0);
  const pendingApprovals = Number(stats.pending_pos || 0) + Number(stats.pending_transfers || 0);
  const lowStockItems = data?.low_stock_items ?? [];
  const tt = data?.transaction_trends ?? { labels: [], values: [], purchase_values: [] };

  const trendData = {
    labels: tt.labels,
    datasets: [
      { label: 'Sales out', data: tt.values, borderColor: PALETTE.crimson, backgroundColor: PALETTE.crimsonSoft, fill: true, tension: 0.35, pointRadius: 2 },
      { label: 'Purchases in', data: tt.purchase_values, borderColor: PALETTE.green, backgroundColor: PALETTE.greenSoft, fill: true, tension: 0.35, pointRadius: 2 },
    ],
  };
  const trendOptions = { ...baseChartOptions, plugins: { ...baseChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, color: PALETTE.ink, font: { size: 11 } } } } };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Branch Operations · {locationName}</p>
              <h1 className="ak-title">Branch Dashboard</h1>
              <p className="ak-sub">Live stock, demand and approvals for your location.</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/inventory')}>View inventory</button>
              <button className="ak-btn ak-btn--primary" onClick={() => navigate('/admin/approvals')}>Approvals</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="SKUs on hand" value={fmt(skus)} tone="green" icon={<Box />} />
          <Kpi label="Low stock" value={fmt(stats.low_stock)} tone="amber" icon={<Alert />} />
          <Kpi label="Pending approvals" value={fmt(pendingApprovals)} tone="crimson" icon={<Check />} />
          <Kpi label="Stock units" value={fmt(units)} tone="blue" icon={<Layers />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Stock movement (last 7 days)</h2><p className="ak-card-meta">Sales out vs purchases in</p></div></div>
          <div style={{ height: 280 }}>
            {tt.labels?.length ? <Line data={trendData} options={trendOptions} /> : <div className="ak-state">No movement recorded in this period.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Low-stock items</h2><p className="ak-card-meta">Below recommended level at {locationName}</p></div></div>
          {lowStockItems.length === 0 ? (
            <div className="ak-state">No low-stock items — your branch is well stocked. 🎉</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Product</th><th>Category</th><th>On hand</th><th>Recommended</th><th>Short by</th></tr></thead>
                <tbody>
                  {lowStockItems.map((it, i) => (
                    <tr key={i}>
                      <td>{it.product_name}</td>
                      <td className="ak-muted">{it.category || '—'}</td>
                      <td>{fmt(it.quantity)}</td>
                      <td>{fmt(it.recommended_stocks)}</td>
                      <td><span className="ak-badge ak-badge--red">{fmt(it.deficiency)}</span></td>
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

const Box = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>);
const Alert = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>);
const Check = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>);
const Layers = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>);
