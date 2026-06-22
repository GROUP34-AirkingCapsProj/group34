import React from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { Bar } from 'react-chartjs-2';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip, Legend } from 'chart.js';
import { useRoleDashboard, Kpi, fmt, PALETTE, baseChartOptions } from './dashboard/dashboardKit';
import '../styles/role_dashboards.css';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const qtyOf = (i) => Number(i.quantity_on_hand ?? i.quantity ?? 0);

export default function InventoryAnalystDashboard() {
  const navigate = useNavigate();
  const { data, inv, loading, error } = useRoleDashboard();

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: PALETTE.crimson }} /><p>Loading analytics…</p></div></AdminLayout>;
  if (error)   return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = data?.stats ?? {};
  const totalSkus = inv.length;
  const outOfStock = inv.filter((i) => qtyOf(i) <= 0).length;
  const units = inv.reduce((s, i) => s + qtyOf(i), 0);
  const cat = data?.inventory_by_category ?? { labels: [], values: [] };
  const byLoc = data?.stock_status_by_location ?? { labels: [], inStock: [], lowStock: [] };
  const lowStockItems = data?.low_stock_items ?? [];

  const catData = { labels: cat.labels, datasets: [{ label: 'Units', data: cat.values, backgroundColor: PALETTE.crimson, borderRadius: 6, maxBarThickness: 42 }] };
  const locData = {
    labels: byLoc.labels,
    datasets: [
      { label: 'Healthy', data: byLoc.inStock, backgroundColor: PALETTE.green, borderRadius: 4, stack: 's' },
      { label: 'Low', data: byLoc.lowStock, backgroundColor: PALETTE.gold, borderRadius: 4, stack: 's' },
    ],
  };
  const stackedOptions = {
    ...baseChartOptions,
    plugins: { ...baseChartOptions.plugins, legend: { display: true, position: 'bottom', labels: { usePointStyle: true, color: PALETTE.ink, font: { size: 11 } } } },
    scales: { x: { ...baseChartOptions.scales.x, stacked: true }, y: { ...baseChartOptions.scales.y, stacked: true } },
  };

  return (
    <AdminLayout>
      <div className="ak-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Inventory Analytics · All Locations</p>
              <h1 className="ak-title">Inventory Analyst Dashboard</h1>
              <p className="ak-sub">Network-wide stock health, category mix and shortfalls.</p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button className="ak-btn ak-btn--ghost" onClick={() => navigate('/admin/po-recommendations')}>Reorder advice</button>
              <button className="ak-btn ak-btn--primary" onClick={() => navigate('/admin/inventory')}>Inventory</button>
            </div>
          </div>
        </header>

        <div className="ak-stat-grid">
          <Kpi label="Total SKUs" value={fmt(totalSkus)} tone="crimson" icon={<Box />} />
          <Kpi label="Low stock" value={fmt(stats.low_stock)} tone="amber" icon={<Alert />} />
          <Kpi label="Out of stock" value={fmt(outOfStock)} tone="crimson" icon={<Zero />} />
          <Kpi label="Stock units" value={fmt(units)} tone="green" icon={<Layers />} />
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Stock status by location</h2><p className="ak-card-meta">Healthy vs low SKUs per location</p></div></div>
          <div style={{ height: 300 }}>
            {byLoc.labels?.length ? <Bar data={locData} options={stackedOptions} /> : <div className="ak-state">No location data.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Inventory by category</h2><p className="ak-card-meta">Units on hand network-wide</p></div></div>
          <div style={{ height: 280 }}>
            {cat.labels?.length ? <Bar data={catData} options={baseChartOptions} /> : <div className="ak-state">No inventory recorded yet.</div>}
          </div>
        </div>

        <div className="ak-card">
          <div className="ak-card-head"><div><h2 className="ak-card-title">Top low-stock items</h2><p className="ak-card-meta">Largest shortfalls across the network</p></div></div>
          {lowStockItems.length === 0 ? (
            <div className="ak-state">No low-stock items network-wide.</div>
          ) : (
            <div className="ak-table-wrap">
              <table className="ak-table">
                <thead><tr><th>Product</th><th>Category</th><th>Brand</th><th>On hand</th><th>Short by</th></tr></thead>
                <tbody>
                  {lowStockItems.map((it, i) => (
                    <tr key={i}>
                      <td>{it.product_name}</td>
                      <td className="ak-muted">{it.category || '—'}</td>
                      <td className="ak-muted">{it.brand || '—'}</td>
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
const Alert = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>);
const Zero = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/></svg>);
const Layers = () => (<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>);
