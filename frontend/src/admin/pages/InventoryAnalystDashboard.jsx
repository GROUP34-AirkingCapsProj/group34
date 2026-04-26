import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { batchAPI, inventoryAPI } from '../services/api';
import '../styles/role_dashboards.css';

import {
  Chart as ChartJS,
  CategoryScale, LinearScale, BarElement, LineElement,
  ArcElement, PointElement, Tooltip, Legend, Filler,
} from 'chart.js';
import { Bar, Line, Pie } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, BarElement, LineElement, ArcElement, PointElement, Tooltip, Legend, Filler);

const ACCENT = '#7c3aed';
const ACCENT_LIGHT = 'rgba(124,58,237,0.12)';

const chartDefaults = (accent) => ({
  bar: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { backgroundColor: '#1f2937', padding: 10, borderRadius: 8 } },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 11 } } },
      y: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280', font: { size: 11 } }, beginAtZero: true },
    },
  },
  line: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom', labels: { color: '#6b7280', usePointStyle: true, pointStyle: 'circle', padding: 14, font: { size: 11 } } },
      tooltip: { backgroundColor: '#1f2937', padding: 10, borderRadius: 8 },
    },
    scales: {
      x: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280', font: { size: 11 } } },
      y: { grid: { color: '#f3f4f6' }, ticks: { color: '#6b7280', font: { size: 11 } }, beginAtZero: true },
    },
    interaction: { intersect: false, mode: 'index' },
  },
  pie: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom', labels: { color: '#6b7280', usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } } },
      tooltip: { backgroundColor: '#1f2937', padding: 10, borderRadius: 8 },
    },
  },
});

const InventoryAnalystDashboard = () => {
  const navigate = useNavigate();
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

  const opts = chartDefaults(ACCENT);

  if (loading) return <AdminLayout><div className="rd-loading"><div className="rd-loading-spinner" style={{ borderTopColor: ACCENT }} /><p>Loading analytics...</p></div></AdminLayout>;
  if (error) return <AdminLayout><div className="rd-error"><p>{error}</p><button onClick={() => window.location.reload()}>Retry</button></div></AdminLayout>;

  const stats = dashData?.stats ?? {};
  const invByCategory = dashData?.inventory_by_category ?? { labels: [], values: [] };
  const stockTrends = dashData?.stock_trends ?? { labels: [], values: [] };
  const branchDist = dashData?.branch_distribution ?? { labels: [], values: [] };

  // Compute low-stock items from inventory list
  const lowStockItems = invData.filter(i => {
    const qoh = Number(i.quantity_on_hand ?? i.quantity ?? 0);
    const threshold = Number(i.reorder_level ?? i.recommended_stocks ?? 5);
    return qoh <= threshold;
  });
  const totalValue = invData.reduce((sum, i) => {
    const qty = Number(i.quantity_on_hand ?? i.quantity ?? 0);
    const cost = Number(i.cost_price ?? i.unit_price ?? 0);
    return sum + qty * cost;
  }, 0);

  const pieColors = ['#7c3aed','#a78bfa','#c4b5fd','#ddd6fe','#ede9fe','#4c1d95'];
  const pieLabels = invByCategory.labels ?? [];
  const pieValues = invByCategory.values ?? [];

  // Stock status breakdown
  const inStockCount = invData.filter(i => Number(i.quantity_on_hand ?? 0) > (Number(i.reorder_level ?? 5))).length;
  const outOfStockCount = invData.filter(i => Number(i.quantity_on_hand ?? 0) === 0).length;
  const lowCount = lowStockItems.length;

  return (
    <AdminLayout>
      {/* HEADER */}
      <div className="rd-header" style={{ '--rd-accent': ACCENT }}>
        <div className="rd-header-left">
          <div className="rd-header-icon" style={{ background: ACCENT_LIGHT, color: ACCENT }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="22" height="22">
              <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
          </div>
          <div>
            <h1 className="rd-title">Inventory Analytics</h1>
            <p className="rd-subtitle">Real-time inventory performance, stock valuation, and trend analysis.</p>
          </div>
        </div>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, alignItems: 'center' }}>
          <button
            type="button"
            className="rd-primary-btn"
            style={{ background: '#fff', color: ACCENT, border: `2px solid ${ACCENT}` }}
            onClick={() => navigate('/admin/inventory')}
          >
            View Full Inventory
          </button>
        </div>
      </div>

      {/* STATS */}
      <div className="rd-stats-grid">
        {[
          { label: 'Total SKUs', value: stats.total_items ?? invData.length, icon: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01', color: ACCENT, bg: ACCENT_LIGHT },
          { label: 'Low Stock Items', value: lowCount, icon: 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01', color: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
          { label: 'Out of Stock', value: outOfStockCount, icon: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4', color: '#ef4444', bg: 'rgba(239,68,68,0.12)' },
          { label: 'Est. Total Value', value: `₱${totalValue.toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`, icon: 'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6', color: '#059669', bg: 'rgba(5,150,105,0.12)' },
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

      {/* QUICK LINKS */}
      <div className="rd-quick-links">
        {[
          { label: 'Restocking Recommendations', path: '/admin/po-recommendations', color: ACCENT },
          { label: 'Issue Inventory', path: '/admin/issuances', color: '#059669' },
          { label: 'Inventory Valuation', path: '/admin/profit-loss', color: '#d97706' },
          { label: 'Audit Trail', path: '/admin/audit', color: '#6b7280' },
        ].map(({ label, path, color }) => (
          <button key={label} className="rd-quick-link-btn" style={{ borderColor: color, color }} onClick={() => navigate(path)}>
            {label}
          </button>
        ))}
      </div>

      {/* CHARTS ROW 1 */}
      <div className="rd-charts-row">
        <div className="rd-chart-card rd-chart-wide">
          <div className="rd-chart-header">
            <h3>Inventory by Category</h3>
            <span className="rd-chart-badge" style={{ background: ACCENT_LIGHT, color: ACCENT }}>{pieLabels.length} categories</span>
          </div>
          <div className="rd-chart-body">
            <Bar
              data={{
                labels: invByCategory.labels,
                datasets: [{ data: invByCategory.values, backgroundColor: ACCENT, borderRadius: 6, maxBarThickness: 64 }],
              }}
              options={opts.bar}
            />
          </div>
        </div>

        <div className="rd-chart-card">
          <div className="rd-chart-header">
            <h3>Stock Status</h3>
          </div>
          <div className="rd-chart-body">
            <Pie
              data={{
                labels: ['In Stock', 'Low Stock', 'Out of Stock'],
                datasets: [{ data: [inStockCount, lowCount, outOfStockCount], backgroundColor: ['#7c3aed','#f59e0b','#ef4444'], borderColor: '#fff', borderWidth: 2 }],
              }}
              options={opts.pie}
            />
          </div>
        </div>
      </div>

      {/* CHARTS ROW 2 */}
      <div className="rd-charts-row">
        <div className="rd-chart-card">
          <div className="rd-chart-header"><h3>Branch Distribution</h3></div>
          <div className="rd-chart-body">
            <Pie
              data={{
                labels: pieLabels,
                datasets: [{ data: pieValues, backgroundColor: pieColors, borderColor: '#fff', borderWidth: 2 }],
              }}
              options={opts.pie}
            />
          </div>
        </div>

        <div className="rd-chart-card rd-chart-wide">
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
              options={opts.line}
            />
          </div>
        </div>
      </div>

      {/* LOW STOCK TABLE */}
      <div className="rd-table-card">
        <div className="rd-table-header">
          <h3>Low Stock Items</h3>
          <button className="rd-table-action" style={{ color: ACCENT }} onClick={() => navigate('/admin/po-recommendations')}>
            Generate Restock Order →
          </button>
        </div>
        {lowStockItems.length === 0 ? (
          <div className="rd-empty"><p>All items are sufficiently stocked.</p></div>
        ) : (
          <div className="rd-table-wrap">
            <table className="rd-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Location</th>
                  <th>On Hand</th>
                  <th>Reorder Level</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {lowStockItems.slice(0, 10).map((item, i) => {
                  const qoh = Number(item.quantity_on_hand ?? item.quantity ?? 0);
                  const level = Number(item.reorder_level ?? item.recommended_stocks ?? 5);
                  const isCritical = qoh === 0 || qoh < level * 0.25;
                  return (
                    <tr key={i}>
                      <td>{item.product?.product_name ?? item.item_name ?? item.name ?? '—'}</td>
                      <td>{item.location?.location_name ?? item.branch?.branch_name ?? '—'}</td>
                      <td><span className={`rd-qty ${isCritical ? 'rd-qty-critical' : 'rd-qty-low'}`}>{qoh}</span></td>
                      <td>{level}</td>
                      <td><span className={`rd-badge ${isCritical ? 'rd-badge-critical' : 'rd-badge-low'}`}>{isCritical ? 'CRITICAL' : 'LOW'}</span></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default InventoryAnalystDashboard;
