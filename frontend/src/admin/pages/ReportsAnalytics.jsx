import React, { lazy, Suspense, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { useAuth } from '../context/AuthContext';
import '../styles/system_reports.css';

const AnalyticsDashboard = lazy(() => import('./AnalyticsDashboard'));
const SystemReports = lazy(() => import('./SystemReports'));

export default function ReportsAnalytics() {
  const { can } = useAuth();
  const [filters, setFilters] = useState({ location_id: '', category_id: '' });
  const [view, setView] = useState('stock-level');
  const [ready, setReady] = useState(false);
  const canAnalytics = can('analytics_dashboard', 'read');
  const canReports = can('report_viewing', 'read');
  const analytics = canAnalytics ? <AnalyticsDashboard embedded initialFilters={filters}
    onFiltersChange={setFilters} onViewChange={setView} onReadyChange={setReady} /> : null;

  return (
    <AdminLayout>
      <div className="ak-page sr-page reports-analytics-page">
        <header className="ak-header">
          <p className="ak-eyebrow">Insights</p>
          <h1 className="ak-title">Reports &amp; Analytics</h1>
          <p className="ak-sub">Review trends, inspect supporting records, and generate reports.</p>
        </header>
        <div className="reports-workspace">
          <Suspense fallback={<Loader label="Loading reports and analytics" />}>
            {canReports ? <SystemReports embedded defaultParameters={filters}
              analyticsView={canAnalytics ? view : null} analyticsReady={ready}>{analytics}</SystemReports> : analytics}
          </Suspense>
        </div>
      </div>
    </AdminLayout>
  );
}
