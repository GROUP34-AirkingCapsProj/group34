import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip, Legend } from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

export default function WarehousePersonnelDashboard() {
  const navigate = useNavigate();
  const { locationName, data, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><Loader variant="page" label="Loading warehouse data" /></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const skus = Number(stats.total_items ?? 0);
  const cat = data?.inventory_by_category ?? { labels: [], values: [] };
  const lowStockItems = data?.low_stock_items ?? [];

  const catData = { labels: cat.labels, datasets: [{ label: 'Units', data: cat.values, backgroundColor: PALETTE.blue, borderRadius: 6, maxBarThickness: 46 }] };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Warehouse · {locationName}</p>
              <h1 className="ak-title">Warehouse Dashboard</h1>
              <p className="ak-sub">Receiving, dispatch and stock health for your warehouse.</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/transfers')}>Transfers</button>
              <button className="ak-btn ak-btn--primary" onClick={() => navigate('/admin/inventory-operation')}>Scan</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="SKUs at warehouse" value={fmt(skus)} tone="blue" icon={<Box />} />
          <Kpi label="Awaiting receipt" value={fmt(stats.awaiting_receipt)} tone="amber" icon={<In />} />
          <Kpi label="To dispatch" value={fmt(stats.awaiting_fulfillment)} tone="crimson" icon={<Out />} />
          <Kpi label="Low stock" value={fmt(stats.low_stock)} tone="green" icon={<Alert />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Inventory by category</h2><p className="ak-card-meta">Units on hand at {locationName}</p></div></div>
          <div style={{ height: 280 }}>
            {cat.labels?.length ? <Bar data={catData} options={baseChartOptions} /> : <div className="ak-state">No inventory recorded yet.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Low-stock items</h2><p className="ak-card-meta">Need restocking</p></div></div>
          {lowStockItems.length === 0 ? (
            <div className="ak-state">Nothing low on stock right now.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Product</th><th>Category</th><th>On hand</th><th>Short by</th></tr></thead>
                <tbody>
                  {lowStockItems.map((it, i) => (
                    <tr key={i}>
                      <td>{it.product_name}</td>
                      <td className="ak-muted">{it.category || '—'}</td>
                      <td>{fmt(it.quantity)}</td>
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
const In = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>);
const Out = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>);
const Alert = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>);
