import React, { useState, useEffect, useRef, useMemo } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import AnalyticsRecords from '../components/reports/AnalyticsRecords';
import { analyticsAPI } from '../services/api';
import { Bar } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
} from 'chart.js';
import '../styles/analytics_dashboard.css';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

const AnalyticsDashboard = ({ embedded = false, initialFilters = {}, onFiltersChange, onViewChange, onReadyChange }) => {
  const Layout = embedded ? React.Fragment : AdminLayout;
  const [loadedKey, setLoadedKey] = useState(null);
  const [snapshotAt, setSnapshotAt] = useState(null);
  const [stockStatus, setStockStatus] = useState('');
  const [activeTab, setActiveTab] = useState('stock-level');
  const [selectedBranch, setSelectedBranch] = useState(initialFilters.location_id || '');
  const [selectedCategory, setSelectedCategory] = useState(initialFilters.category_id || '');
  const [initialLoading, setInitialLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState(null);
  const [branches, setBranches] = useState([]);
  const [categories, setCategories] = useState([]);
  const hasLoadedOnce = useRef(false);

  // Raw processed data stored in a single state object to batch updates
  const [tabData, setTabData] = useState({
    // Stock Level
    statsData: { totalStockValue: 0, totalUnits: 0, lowStockItems: 0, criticalItems: 0 },
    stockDistribution: {
      optimal: { count: 0, percentage: 0 }, lowStock: { count: 0, percentage: 0 },
      critical: { count: 0, percentage: 0 }, overstock: { count: 0, percentage: 0 }
    },
    reorderCompliance: { rate: 0, belowReorderPoint: 0, aboveSafetyStock: 0 },
    criticalItems: [],
    overstockItems: [],
    // Movement
    movementStats: { avgTurnoverRate: 0, fastMovingCount: 0, slowMovingCount: 0, optimalStockCount: 0 },
    fastMovingItems: [],
    slowMovingItems: [],
    consumptionData: [],
    weeklyMovement: [0, 0, 0, 0],
    stockRecords: [], movementRecords: [], lossRecords: [], movementPeriod: null,
    // Predictions
    predictionsStats: { predictedStockouts: 0, restockNeeded: 0, criticalPriority: 0, estRestockCost: 0 },
    stockoutTimeline: [],
    restockRecommendations: [],
    prioritySummary: {
      critical: { count: 0, units: 0, cost: 0 },
      high: { count: 0, units: 0, cost: 0 },
      medium: { count: 0, units: 0, cost: 0 },
      low: { count: 0, units: 0, cost: 0 }
    },
    // Profit Loss
    profitLossStats: { totalProfitLoss: 0, stolenUnits: 0, damagedUnits: 0, pendingReports: 0 },
    lossByBranch: [],
    lossByCategory: [],
    recentLossIncidents: [],
    // Branch Performance
    branchPerformanceData: [],
    branchRestockingNeeds: [],
  });

  // Destructure for render access (avoids changing all JSX references)
  const {
    statsData, stockDistribution, reorderCompliance, criticalItems, overstockItems,
    movementStats, fastMovingItems, slowMovingItems, consumptionData, weeklyMovement,
    predictionsStats, stockoutTimeline, restockRecommendations, prioritySummary,
    profitLossStats, lossByBranch, lossByCategory, recentLossIncidents,
    branchPerformanceData, branchRestockingNeeds,
  } = tabData;


  // One request per tab. The server returns exactly the shape each tab renders,
  // so there is no client-side computation left to do.
  const TAB_FETCHERS = useMemo(() => ({
    'stock-level':        analyticsAPI.getStockLevel,
    'movement':           analyticsAPI.getMovement,
    'predictions':        analyticsAPI.getPredictions,
    'profit-loss':        analyticsAPI.getProfitLoss,
    'branch-performance': analyticsAPI.getLocationPerformance,
  }), []);

  // Load the filter dropdowns once.
  useEffect(() => {
    let cancelled = false;

    analyticsAPI.getFilters()
      .then((res) => {
        if (cancelled) return;
        const data = res?.data ?? {};
        setBranches(Array.isArray(data.locations) ? data.locations : []);
        setCategories(Array.isArray(data.categories) ? data.categories : []);
      })
      .catch((err) => {
        console.error('Failed to load analytics filters:', err);
      });

    return () => { cancelled = true; };
  }, []);

  const requestKey = `${activeTab}:${selectedBranch}:${selectedCategory}`;
  const ready = loadedKey === requestKey && !refreshing && !initialLoading && !error;
  useEffect(() => { onViewChange?.(activeTab); }, [activeTab, onViewChange]);
  useEffect(() => { onReadyChange?.(ready); }, [ready, onReadyChange]);

  // Refetch whenever the tab or either filter changes.
  useEffect(() => {
    let cancelled = false;
    const fetcher = TAB_FETCHERS[activeTab];
    if (!fetcher) return undefined;

    setLoadedKey(null);
    setError(null);
    const isFirstLoad = !hasLoadedOnce.current;
    if (isFirstLoad) setInitialLoading(true);
    else setRefreshing(true);

    fetcher({ location_id: selectedBranch, category_id: selectedCategory })
      .then((res) => {
        if (cancelled) return;
        setTabData((prev) => ({ ...prev, ...(res?.data ?? {}) }));
        setLoadedKey(requestKey);
        setSnapshotAt(res?.generated_at || new Date().toISOString());
        hasLoadedOnce.current = true;
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message);
        console.error('Failed to fetch analytics data:', err);
      })
      .finally(() => {
        if (cancelled) return;
        setInitialLoading(false);
        setRefreshing(false);
      });

    return () => { cancelled = true; };
  }, [TAB_FETCHERS, activeTab, selectedBranch, selectedCategory, requestKey]);



  // Memoized chart data — only recalculates when underlying data changes
  const movementChartData = useMemo(() => ({
    labels: tabData.movementPeriod?.labels || ['Oldest period', 'Week 2', 'Week 3', 'Latest week'],
    datasets: [{
      label: 'Units consumed',
      data: weeklyMovement,
      backgroundColor: '#3b82f6',
      borderRadius: 4,
      barThickness: 40,
    }],
  }), [weeklyMovement, tabData.movementPeriod]);

  const movementChartOptions = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { enabled: true } },
    scales: {
      x: { grid: { display: false }, ticks: { color: 'var(--text-muted)', font: { size: 12 } } },
      y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Units consumed' } },
    },
  }), []);

  const branchChartData = useMemo(() => ({
    labels: branchPerformanceData.map(b => b.name),
    datasets: [{
      label: 'Reorder compliance (%)',
      data: branchPerformanceData.map(b => b.efficiencyScore),
      backgroundColor: '#3b82f6',
      borderRadius: 4,
      barThickness: 40,
    }],
  }), [branchPerformanceData]);

  const branchChartOptions = useMemo(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { enabled: true } },
    scales: {
      x: { grid: { display: false }, ticks: { color: 'var(--text-muted)', font: { size: 11 } } },
      y: {
        display: true,
        grid: { color: '#f3f4f6' },
        ticks: { color: 'var(--text-label)', font: { size: 11 } },
        min: 0,
        max: 100,
      },
    },
  }), []);

  const renderStockLevelTab = () => (
    <>
      {/* Stats Cards */}
      <div className="analytics-stats-grid">
        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper cyan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="12" y1="1" x2="12" y2="23"></line>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Stock Value at Cost</span>
            <span className="stat-value">₱{statsData.totalStockValue.toLocaleString()}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper cyan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Total Units</span>
            <span className="stat-value">{statsData.totalUnits}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Low Stock Items</span>
            <span className="stat-value">{statsData.lowStockItems}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Critical Items</span>
            <span className="stat-value">{statsData.criticalItems}</span>
          </div>
        </div>
      </div>

      {/* Distribution and Compliance Section */}
      <div className="analytics-section-grid">
        {/* Stock Status Distribution */}
        <div className="analytics-card">
          <h3>Stock Status Distribution</h3>
          <p className="card-subtitle">Current inventory health</p>

          <div className="distribution-list">
            <div className="distribution-item">
              <div className="distribution-label">
                <span className="status-dot optimal"></span>
                <span>Optimal</span>
              </div>
              <span className="distribution-count">{stockDistribution.optimal.count}<span className="percentage">({stockDistribution.optimal.percentage}%)</span></span>
            </div>

            <div className="distribution-item">
              <div className="distribution-label">
                <span className="status-dot low-stock"></span>
                <span>Low Stock</span>
              </div>
              <span className="distribution-count">{stockDistribution.lowStock.count}<span className="percentage">({stockDistribution.lowStock.percentage}%)</span></span>
            </div>

            <div className="distribution-item">
              <div className="distribution-label">
                <span className="status-dot critical"></span>
                <span>Critical</span>
              </div>
              <span className="distribution-count">{stockDistribution.critical.count}<span className="percentage">({stockDistribution.critical.percentage}%)</span></span>
            </div>

            <div className="distribution-item">
              <div className="distribution-label">
                <span className="status-dot overstock"></span>
                <span>Overstock</span>
              </div>
              <span className="distribution-count">{stockDistribution.overstock.count}<span className="percentage">({stockDistribution.overstock.percentage}%)</span></span>
            </div>
          </div>
        </div>

        {/* Reorder Point Compliance */}
        <div className="analytics-card">
          <h3>Reorder Point Compliance</h3>
          <p className="card-subtitle">Stock level adherence</p>

          <div className="compliance-section">
            <div className="compliance-header">
              <span className="compliance-label">Overall Compliance Rate</span>
              <span className="compliance-rate">{reorderCompliance.rate}%</span>
            </div>
            <div className="compliance-bar-container">
              <div className="compliance-bar" style={{ width: `${reorderCompliance.rate}%` }}></div>
            </div>

            <div className="compliance-stats">
              <div className="compliance-stat below">
                <span className="compliance-stat-label">Below Reorder Point</span>
                <span className="compliance-stat-value">{reorderCompliance.belowReorderPoint}</span>
              </div>
              <div className="compliance-stat above">
                <span className="compliance-stat-label">Above Reorder Point</span>
                <span className="compliance-stat-value">{reorderCompliance.aboveSafetyStock}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Items Lists Section */}
      <div className="analytics-section-grid">
        {/* Critical & Low Stock Items */}
        <div className="analytics-card">
          <h3>Critical & Low Stock Items</h3>
          <p className="card-subtitle">{criticalItems.length} items need attention (showing up to 10)</p>

          <div className="items-list">
            {criticalItems.length === 0 ? (
              <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No critical or low stock items</p>
            ) : (
              criticalItems.slice(0, 10).map((item, index) => (
                <div key={index} className="item-row critical-item">
                  <div className="item-indicator warning"></div>
                  <div className="item-details">
                    <span className="item-name">{item.name}</span>
                    <span className="item-location">{item.location}</span>
                  </div>
                  <div className="item-stock">
                    <span className="item-current warning">{item.current}</span>
                    <span className="item-separator">/</span>
                    <span className="item-max">{item.max}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Overstock Items */}
        <div className="analytics-card">
          <h3>Overstock Items</h3>
          <p className="card-subtitle">{overstockItems.length} items exceed optimal levels (showing up to 10)</p>

          <div className="items-list">
            {overstockItems.length === 0 ? (
              <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No overstock items</p>
            ) : (
              overstockItems.slice(0, 10).map((item, index) => (
                <div key={index} className="item-row overstock-item">
                  <div className="item-indicator overstock"></div>
                  <div className="item-details">
                    <span className="item-name">{item.name}</span>
                    <span className="item-location">{item.location}</span>
                  </div>
                  <div className="item-stock">
                    <span className="item-current overstock">{item.current}</span>
                    <span className="item-optimal">Optimal: {item.optimal}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </>
  );

  const renderMovementTab = () => (
    <>
      {/* Movement Stats Cards */}
      <div className="analytics-stats-grid">
        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper cyan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Avg Turnover Rate</span>
            <span className="stat-value">{movementStats.avgTurnoverRate}x/mo</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="13 17 18 12 13 7"></polyline>
              <polyline points="6 17 11 12 6 7"></polyline>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Fast-Moving Items</span>
            <span className="stat-value">{movementStats.fastMovingCount}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Slow-Moving Items</span>
            <span className="stat-value">{movementStats.slowMovingCount}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Optimal Stock</span>
            <span className="stat-value">{movementStats.optimalStockCount}</span>
          </div>
        </div>
      </div>

      {/* Stock Movement Trends */}
      <div className="analytics-card" style={{ marginBottom: '24px' }}>
        <h3>Stock Movement Trends</h3>
        <p className="card-subtitle">Last 30 days; the oldest period covers 9 days</p>
        <div className="movement-chart-container">
          <Bar data={movementChartData} options={movementChartOptions} />
        </div>
      </div>

      {/* Fast & Slow Moving Items */}
      <div className="analytics-section-grid">
        {/* Fast-Moving Items */}
        <div className="analytics-card">
          <h3>Fast-Moving Items</h3>
          <p className="card-subtitle">Turnover of at least 6x/month (showing up to 10)</p>

          <div className="movement-items-list">
            {fastMovingItems.length === 0 ? (
              <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No fast-moving items detected</p>
            ) : (
              fastMovingItems.slice(0, 10).map((item, index) => (
                <div key={index} className="movement-item-row">
                  <div className="movement-rank fast">{index + 1}</div>
                  <div className="movement-item-details">
                    <span className="movement-item-name">{item.name}</span>
                    <span className="movement-item-category">{item.category}</span>
                  </div>
                  <div className="movement-item-rate">
                    <span className="rate-value">{item.rate}x</span>
                    <span className="rate-label">per month</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Slow-Moving Items */}
        <div className="analytics-card slow-moving-card">
          <h3>Slow-Moving Items</h3>
          <p className="card-subtitle">Turnover above 0 and up to 3x/month (showing up to 10)</p>

          <div className="movement-items-list">
            {slowMovingItems.length === 0 ? (
              <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No slow-moving items detected</p>
            ) : (
              slowMovingItems.slice(0, 10).map((item, index) => (
                <div key={index} className="movement-item-row">
                  <div className="movement-rank slow">{index + 1}</div>
                  <div className="movement-item-details">
                    <span className="movement-item-name">{item.name}</span>
                    <span className="movement-item-category">{item.category}</span>
                  </div>
                  <div className="movement-item-rate">
                    <span className="rate-value slow">{item.rate}x</span>
                    <span className="rate-label">per month</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

      {/* Consumption Rate Analysis Table */}
      <div className="analytics-card">
        <h3>Consumption Rate Analysis</h3>
        <p className="card-subtitle">Top 10 items by consumption</p>

        <div className="consumption-table-wrapper">
          <table className="consumption-table">
            <thead>
              <tr>
                <th>ITEM</th>
                <th>CURRENT STOCK</th>
                <th>CONSUMPTION RATE</th>
                <th>TURNOVER</th>
                <th>STATUS</th>
              </tr>
            </thead>
            <tbody>
              {consumptionData.length === 0 ? (
                <tr>
                  <td colSpan="5" style={{ textAlign: 'center', color: 'var(--ink-slate-400)', padding: '24px' }}>
                    No consumption data available
                  </td>
                </tr>
              ) : (
                consumptionData.slice(0, 10).map((item, index) => (
                  <tr key={index}>
                    <td className="consumption-item-name">{item.name}</td>
                    <td className="consumption-stock">{item.currentStock}</td>
                    <td className="consumption-rate-cell">
                      <div className="consumption-bar-wrapper">
                        <div
                          className={`consumption-bar ${item.consumptionRate < 0 ? 'negative' : 'positive'}`}
                          style={{ width: `${Math.min(Math.abs(item.consumptionRate), 100)}%` }}
                        ></div>
                      </div>
                      <span className={`consumption-percentage ${item.consumptionRate < 0 ? 'negative' : ''}`}>
                        {item.consumptionRate < 0 ? '' : ''}{Math.abs(item.consumptionRate)}%
                      </span>
                    </td>
                    <td className="consumption-turnover">{item.turnover}</td>
                    <td>
                      <span className={`consumption-status ${item.status === 'In Stock' ? 'in-stock' : item.status === 'Low Stock' ? 'low-stock' : 'out-of-stock'}`}>
                        {item.status}
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );

  const renderPredictionsTab = () => (
    <>
      {/* Predictions Stats Cards */}
      <div className="analytics-stats-grid">
        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Predicted Stockouts</span>
            <span className="stat-value">{predictionsStats.predictedStockouts}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Restock Needed</span>
            <span className="stat-value">{predictionsStats.restockNeeded}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Critical Priority</span>
            <span className="stat-value">{predictionsStats.criticalPriority}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper cyan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="12" y1="1" x2="12" y2="23"></line>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Est. Restock Cost</span>
            <span className="stat-value">₱{predictionsStats.estRestockCost.toLocaleString()}</span>
          </div>
        </div>
      </div>

      {/* Projected Stock-Out Timeline */}
      <div className="analytics-card pred-timeline-card">
        <h3>Projected Stock-Out Timeline</h3>
        <p className="card-subtitle">Warehouse replenishment - predicted stockouts within 30 days (showing up to 10)</p>

        <div className="pred-timeline-list">
          {stockoutTimeline.length === 0 ? (
            <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No items predicted to stock out within 30 days</p>
          ) : (
            stockoutTimeline.slice(0, 10).map((item, index) => (
              <div key={index} className="pred-timeline-item">
                <div className="pred-timeline-item-content">
                  <div className="pred-timeline-item-header">
                    <span className="pred-timeline-item-name">{item.name}</span>
                  </div>
                  <span className="pred-timeline-item-branch">{item.branch} &bull; Current: {item.currentStock} units</span>
                  <div className="pred-timeline-item-meta">
                    <span>Daily demand <strong>{item.consumptionPct} units/day</strong></span>
                    <span>Reorder Level <strong>{item.reorderLevel}</strong></span>
                    <span>Suggested <strong className="pred-suggested">{item.suggestedQty} units</strong></span>
                  </div>
                </div>
                <div className="pred-timeline-days">
                  <span className="pred-days-value">-{item.daysLeft}</span>
                  <span className="pred-days-label">days left</span>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {/* Restocking Recommendation List */}
      <div className="analytics-card pred-restock-card">
        <h3>Restocking Recommendation List</h3>
        <p className="card-subtitle">{restockRecommendations.length} items require restocking (showing up to 10)</p>

        <div className="consumption-table-wrapper">
          <table className="consumption-table pred-restock-table">
            <thead>
              <tr>
                <th>PRIORITY</th>
                <th>ITEM</th>
                <th>LOCATION</th>
                <th>CURRENT</th>
                <th>REORDER POINT</th>
                <th>SAFETY STOCK</th>
                <th>SUGGESTED QTY</th>
                <th>EST. COST</th>
              </tr>
            </thead>
            <tbody>
              {restockRecommendations.length === 0 ? (
                <tr>
                  <td colSpan="8" style={{ textAlign: 'center', color: 'var(--ink-slate-400)', padding: '24px' }}>
                    No restocking recommendations
                  </td>
                </tr>
              ) : (
                restockRecommendations.slice(0, 10).map((item, index) => (
                  <tr key={index}>
                    <td>
                      <span className={`pred-priority-badge ${item.priority}`}>
                        {item.priority.toUpperCase()}
                      </span>
                    </td>
                    <td className="consumption-item-name">{item.name}</td>
                    <td>{item.branch}</td>
                    <td><span className="pred-current-value">{item.currentStock}</span></td>
                    <td>{item.reorderLevel}</td>
                    <td>{item.safetyStock}</td>
                    <td><span className="pred-suggested-value">{item.suggestedQty}</span></td>
                    <td className="pred-cost-value">₱{item.estCost.toLocaleString()}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Priority Summary Cards */}
      <div className="pred-priority-grid">
        <div className="pred-priority-card critical">
          <div className="pred-priority-card-header">
            <h4>Critical Priority</h4>
            <span className="pred-priority-count">{prioritySummary.critical.count} items</span>
          </div>
          <div className="pred-priority-card-body">
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Total Units Needed</span>
              <span className="pred-priority-metric-value">{prioritySummary.critical.units}</span>
            </div>
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Estimated Cost</span>
              <span className="pred-priority-metric-value">₱{prioritySummary.critical.cost.toLocaleString()}</span>
            </div>
          </div>
        </div>

        <div className="pred-priority-card high">
          <div className="pred-priority-card-header">
            <h4>High Priority</h4>
            <span className="pred-priority-count">{prioritySummary.high.count} items</span>
          </div>
          <div className="pred-priority-card-body">
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Total Units Needed</span>
              <span className="pred-priority-metric-value">{prioritySummary.high.units}</span>
            </div>
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Estimated Cost</span>
              <span className="pred-priority-metric-value">₱{prioritySummary.high.cost.toLocaleString()}</span>
            </div>
          </div>
        </div>

        <div className="pred-priority-card medium">
          <div className="pred-priority-card-header">
            <h4>Medium Priority</h4>
            <span className="pred-priority-count">{prioritySummary.medium.count} items</span>
          </div>
          <div className="pred-priority-card-body">
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Total Units Needed</span>
              <span className="pred-priority-metric-value">{prioritySummary.medium.units}</span>
            </div>
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Estimated Cost</span>
              <span className="pred-priority-metric-value">₱{prioritySummary.medium.cost.toLocaleString()}</span>
            </div>
          </div>
        </div>
        {prioritySummary.low && <div className="pred-priority-card low">
          <div className="pred-priority-card-header">
            <h4>Low Priority</h4>
            <span className="pred-priority-count">{prioritySummary.low.count} items</span>
          </div>
          <div className="pred-priority-card-body">
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Total Units Needed</span>
              <span className="pred-priority-metric-value">{prioritySummary.low.units}</span>
            </div>
            <div className="pred-priority-metric">
              <span className="pred-priority-metric-label">Estimated Cost</span>
              <span className="pred-priority-metric-value">₱{prioritySummary.low.cost.toLocaleString()}</span>
            </div>
          </div>
        </div>}
      </div>
    </>
  );

  const renderProfitLossTab = () => (
    <>
      {/* Profit Loss Stats Cards */}
      <div className="analytics-stats-grid">
        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
              <polyline points="17 6 23 6 23 12"></polyline>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Confirmed Stock Loss</span>
            <span className="stat-value">₱{profitLossStats.totalProfitLoss.toLocaleString()}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="4" width="20" height="16" rx="2"></rect>
              <path d="M12 9v4"></path>
              <path d="M12 17h.01"></path>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Missing Units</span>
            <span className="stat-value">{profitLossStats.stolenUnits}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Damaged Units</span>
            <span className="stat-value">{profitLossStats.damagedUnits}</span>
          </div>
        </div>

        <div className="analytics-stat-card">
          <div className="stat-icon-wrapper cyan">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
          </div>
          <div className="stat-details">
            <span className="stat-label">Pending Reports</span>
            <span className="stat-value">{profitLossStats.pendingReports}</span>
          </div>
        </div>
      </div>

      {/* Loss by Branch & Loss by Category */}
      <div className="analytics-section-grid">
        {/* Loss by Branch */}
        <div className="analytics-card">
        <h3>Loss by Location</h3>
          <p className="card-subtitle">Confirmed stock loss by location</p>

          <div className="loss-branch-list">
            {lossByBranch.length === 0 ? (
              <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No loss data by location</p>
            ) : (
              lossByBranch.map((branch, index) => (
                <div key={index} className="loss-branch-item">
                  <div className="loss-branch-badge">
                    {branch.name.substring(0, 3).toUpperCase()}
                  </div>
                  <span className="loss-branch-name">{branch.name}</span>
                  <span className="loss-branch-amount">₱{branch.amount.toLocaleString()}</span>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Loss by Category */}
        <div className="analytics-card">
          <h3>Loss by Category</h3>
          <p className="card-subtitle">Confirmed losses by product category</p>

          <div className="loss-category-list">
            {lossByCategory.length === 0 ? (
              <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No loss data by category</p>
            ) : (
              lossByCategory.map((cat, index) => (
                <div key={index} className="loss-category-item">
                  <div className="loss-category-header">
                    <span className="loss-category-name">{cat.name.toUpperCase()}</span>
                    <span className="loss-category-amount">₱{cat.amount.toLocaleString()}</span>
                  </div>
                  <div className="loss-category-bar-wrapper">
                    <div
                      className="loss-category-bar"
                      style={{ width: `${cat.percentage}%` }}
                    ></div>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

      {/* Confirmed Loss Records */}
      <div className="analytics-card">
        <h3>Confirmed Loss Records</h3>
        <p className="card-subtitle">Confirmed deductions only; pending reports are excluded from loss totals.</p>

        <div className="consumption-table-wrapper">
          <table className="consumption-table loss-incidents-table">
            <thead>
              <tr>
                <th>DATE & ITEM</th>
                <th>TYPE</th>
                <th>QTY</th>
                <th>LOSS VALUE</th>
                <th>REASON</th>
                <th>STATUS</th>
              </tr>
            </thead>
            <tbody>
              {recentLossIncidents.length === 0 ? (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', color: 'var(--ink-slate-400)', padding: '24px' }}>
                    No loss incidents recorded
                  </td>
                </tr>
              ) : (
                recentLossIncidents.slice(0, 10).map((incident, index) => (
                  <tr key={index}>
                    <td className="loss-incident-item-cell">
                      <span className="loss-incident-date">
                        {incident.date ? new Date(incident.date).toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : 'N/A'}
                      </span>
                      <span className="loss-incident-item-name">{incident.itemName}</span>
                      <span className="loss-incident-branch">{incident.branchName}</span>
                    </td>
                    <td>
                      <span className={`loss-type-badge ${incident.lossType}`}>
                        {incident.lossType.toUpperCase()}
                      </span>
                    </td>
                    <td className="loss-incident-qty">{incident.qty}</td>
                    <td className="loss-incident-value">₱{incident.lossValue.toLocaleString()}</td>
                    <td className="loss-incident-reason">{incident.reason}</td>
                    <td>
                      <span className={`loss-status-badge ${incident.status}`}>
                        {incident.status.toUpperCase()}
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );

  const renderBranchPerformanceTab = () => (
    <>
      {/* Cross-Location Performance Comparison Chart */}
      <div className="analytics-card" style={{ marginBottom: '24px' }}>
        <h3>Location Reorder Compliance</h3>
        <p className="card-subtitle">Percentage of stock lines above their reorder point</p>
        <div className="movement-chart-container" style={{ height: '260px' }}>
          {branchPerformanceData.length > 0 ? (
            <Bar data={branchChartData} options={branchChartOptions} />
          ) : (
            <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '40px' }}>No location data available</p>
          )}
        </div>
      </div>

      {/* Location Performance Rankings */}
      <div className="analytics-card" style={{ marginBottom: '24px' }}>
        <h3>Location Performance Rankings</h3>
        <p className="card-subtitle">Sorted by reorder compliance</p>

        <div className="bp-rankings-list">
          {branchPerformanceData.length === 0 ? (
            <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No location performance data</p>
          ) : (
            branchPerformanceData.map((branch, index) => (
              <div key={branch.id} className="bp-ranking-card">
                <div className="bp-ranking-header">
                  <div className="bp-ranking-left">
                    <div className={`bp-rank-circle ${index < 3 ? 'top' : ''}`}>{index + 1}</div>
                    <div className="bp-rank-info">
                      <span className="bp-rank-name">{branch.name}</span>
                      <span className="bp-rank-items">{branch.items} Items</span>
                    </div>
                  </div>
                  <div className="bp-ranking-right">
                    <span className="bp-efficiency-value">{branch.efficiencyScore}%</span>
                    <span className="bp-efficiency-label">Efficiency Score</span>
                  </div>
                </div>

                <div className="bp-ranking-metrics">
                  <div className="bp-metric">
                    <span className="bp-metric-label">Stock Value</span>
                    <span className="bp-metric-value">{branch.stockValueFormatted}</span>
                  </div>
                  <div className="bp-metric">
                    <span className="bp-metric-label">Low Stock</span>
                    <span className={`bp-metric-value ${branch.lowStockCount > 0 ? 'danger' : 'success'}`}>{branch.lowStockCount}</span>
                  </div>
                  <div className="bp-metric">
                    <span className="bp-metric-label">Avg Turnover</span>
                    <span className="bp-metric-value">{branch.avgTurnover}x/mo</span>
                  </div>
                  <div className="bp-metric">
                    <span className="bp-metric-label">Performance</span>
                    <span className={`bp-performance-badge ${branch.performance === 'Excellent' ? 'excellent' : 'needs-improvement'}`}>
                      {branch.performance}
                    </span>
                  </div>
                </div>

                <div className="bp-progress-bar-wrapper">
                  <div
                    className={`bp-progress-bar ${branch.performance === 'Excellent' ? 'excellent' : 'needs-improvement'}`}
                    style={{ width: `${branch.efficiencyScore}%` }}
                  ></div>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {/* Location-Specific Restocking Needs */}
      <div className="analytics-card">
        <h3>Location-Specific Restocking Needs</h3>
        <p className="card-subtitle">Items requiring restock by location</p>

        <div className="bp-restock-grid">
          {branchRestockingNeeds.length === 0 ? (
            <p style={{ color: 'var(--ink-slate-400)', textAlign: 'center', padding: '20px' }}>No restocking data</p>
          ) : (
            branchRestockingNeeds.map((branch, index) => (
              <div key={index} className="bp-restock-card">
                <h4 className="bp-restock-branch-name">{branch.name}</h4>
                <div className="bp-restock-metrics">
                  <div className="bp-restock-metric">
                    <span className="bp-restock-metric-label">Items Needing Restock</span>
                    <span className="bp-restock-metric-value">{branch.restockNeeded}</span>
                  </div>
                  <div className="bp-restock-metric">
                    <span className="bp-restock-metric-label">Critical Priority</span>
                    <span className={`bp-restock-metric-value ${branch.criticalPriority > 0 ? 'danger' : 'success'}`}>{branch.criticalPriority}</span>
                  </div>
                  <div className="bp-restock-metric">
                    <span className="bp-restock-metric-label">Est. Cost</span>
                    <span className="bp-restock-metric-value bold">₱{branch.restockCost.toLocaleString()}</span>
                  </div>
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </>
  );

  const col = (key, label, money = false) => ({ key, label, money });
  const datasets = {
    'stock-level': { title: 'Stock records', rows: tabData.stockRecords.filter(row => !stockStatus || row.status === stockStatus), columns: [
      col('product', 'Product'), col('product_code', 'Code'), col('category', 'Category'), col('location', 'Location'),
      col('quantity_on_hand', 'On hand'), col('available_quantity', 'Available'), col('threshold', 'Reorder point'),
      col('status', 'Stock status'), col('cost_price', 'Cost per piece', true), col('stock_value', 'Stock value at cost', true),
    ] },
    movement: { title: 'Movement records', rows: tabData.movementRecords, columns: [
      col('name', 'Product'), col('category', 'Category'), col('onHand', 'On hand'), col('unitsOut', 'Units consumed (30 days)'),
      col('week1', 'Oldest 9 days'), col('week2', 'Week 2'), col('week3', 'Week 3'), col('week4', 'Latest 7 days'), col('turnover', 'Turnover ratio'),
    ] },
    predictions: { title: 'Restocking records', rows: restockRecommendations, columns: [
      col('name', 'Product'), col('branch', 'Warehouse'), col('priority', 'Priority'), col('currentStock', 'On hand'),
      col('reorderLevel', 'Reorder point'), col('suggestedQty', 'Suggested units'), col('estCost', 'Estimated cost', true),
      col('daysOfCover', 'Days of cover'), col('stockoutRisk', 'Stockout within 30 days'), col('dailyDemand', 'Units / day'), col('computedAt', 'Computed at'),
    ] },
    'profit-loss': { title: 'Confirmed loss records', rows: tabData.lossRecords, columns: [
      col('date', 'Report date'), col('itemName', 'Product'), col('branchName', 'Location'), col('itemCategory', 'Category'),
      col('lossType', 'Type'), col('qty', 'Units lost'), col('lossValue', 'Confirmed loss at cost', true), col('reason', 'Reason'),
    ] },
    'branch-performance': { title: 'Location performance records', rows: branchPerformanceData, columns: [
      col('name', 'Location'), col('items', 'Stock lines'), col('totalValue', 'Stock value at cost', true), col('lowStockCount', 'Low / critical lines'),
      col('criticalPriority', 'Out-of-stock lines'), col('restockNeeded', 'Restock lines'), col('restockCost', 'Restock cost', true),
      col('avgTurnover', 'Turnover ratio'), col('efficiencyScore', 'Reorder compliance (%)'),
    ] },
  };
  const records = datasets[activeTab];
  const context = {
    Location: branches.find(branch => String(branch.id) === selectedBranch)?.name || (branches.length === 1 ? branches[0].name : 'Available locations'),
    Category: categories.find(category => String(category.id) === selectedCategory)?.name || 'All categories',
    ...(activeTab === 'movement' && tabData.movementPeriod ? { Period: `${tabData.movementPeriod.from} to ${tabData.movementPeriod.to}` } : {}),
    ...(activeTab === 'predictions' ? { Basis: 'Saved restock estimates; each row shows its calculation time' } : {}),
    ...(activeTab === 'profit-loss' ? { Basis: 'Confirmed stock deductions at cost; open reports excluded' } : {}),
    Snapshot: snapshotAt ? new Date(snapshotAt).toLocaleString('en-PH') : '',
    ...(activeTab === 'stock-level' ? { 'Stock status': stockStatus || 'All statuses' } : {}),
  };

  return (
    <Layout>
      <div className="ak-page analytics-content">
        {/* Page Title */}
        {!embedded && <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Insights · Analytics</p>
              <h1 className="ak-title">Analytics Dashboard</h1>
              <p className="ak-sub">Stock trends and supporting records using the same location and category filters.</p>
            </div>
          </div>
        </header>}

        {initialLoading && <Loader label="Loading analytics data" />}
        {refreshing && <div className="refreshing-indicator">Updating...</div>}
        {error && !initialLoading && <div className="error-message">Error: {error}</div>}

        {/* Filter Section */}
        <div className="analytics-filter-section">
          <label>Filter by:</label>
          <select
            aria-label="Category"
            value={selectedCategory}
            onChange={(e) => {
              setSelectedCategory(e.target.value);
              setStockStatus('');
              onFiltersChange?.({ location_id: selectedBranch, category_id: e.target.value });
            }}
            className="branch-filter-select"
          >
            <option value="">All Category</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>{cat.name}</option>
            ))}
          </select>
          <select
            aria-label="Location"
            value={selectedBranch}
            onChange={(e) => {
              setSelectedBranch(e.target.value);
              setStockStatus('');
              onFiltersChange?.({ location_id: e.target.value, category_id: selectedCategory });
            }}
            className="branch-filter-select"
          >
            <option value="">Available locations</option>
            {branches.map(branch => (
              <option key={branch.id} value={branch.id}>{branch.name}</option>
            ))}
          </select>
        </div>

        {/* Tab Navigation */}
        <div className="analytics-tabs">
          <button
            className={`tab-btn ${activeTab === 'stock-level' ? 'active' : ''}`}
            onClick={() => setActiveTab('stock-level')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M12 6v6l4 2"></path>
            </svg>
            Stock Level Analysis
          </button>
          <button
            className={`tab-btn ${activeTab === 'movement' ? 'active' : ''}`}
            onClick={() => setActiveTab('movement')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
            </svg>
            Movement & Turnover
          </button>
          <button
            className={`tab-btn ${activeTab === 'predictions' ? 'active' : ''}`}
            onClick={() => setActiveTab('predictions')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
              <polyline points="17 6 23 6 23 12"></polyline>
            </svg>
            Predictions & Restocking
          </button>
          <button
            className={`tab-btn ${activeTab === 'profit-loss' ? 'active' : ''}`}
            onClick={() => setActiveTab('profit-loss')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="12" y1="1" x2="12" y2="23"></line>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
            Stock Loss Analysis
          </button>
          <button
            className={`tab-btn ${activeTab === 'branch-performance' ? 'active' : ''}`}
            onClick={() => setActiveTab('branch-performance')}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <line x1="18" y1="20" x2="18" y2="10"></line>
              <line x1="12" y1="20" x2="12" y2="4"></line>
              <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            Location Performance
          </button>
        </div>

        {/* Tab Content */}
        {ready && activeTab === 'stock-level' && renderStockLevelTab()}
        {ready && activeTab === 'movement' && renderMovementTab()}
        {ready && activeTab === 'predictions' && renderPredictionsTab()}
        {ready && activeTab === 'profit-loss' && renderProfitLossTab()}
        {ready && activeTab === 'branch-performance' && renderBranchPerformanceTab()}
        {ready && <AnalyticsRecords key={`${requestKey}:${stockStatus}`} {...records} context={context}
          filter={activeTab === 'stock-level' ? <label>Stock status <select aria-label="Stock status" value={stockStatus} onChange={event => setStockStatus(event.target.value)}>
            <option value="">All statuses</option><option value="optimal">Optimal</option><option value="low">Low stock</option><option value="critical">Critical</option><option value="overstock">Overstock</option>
          </select></label> : null} />}
      </div>

    </Layout>
  );
};

export default AnalyticsDashboard;
