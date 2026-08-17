import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import GenerateReportDialog from '../components/reports/GenerateReportDialog';
import RecentReportsList from '../components/reports/RecentReportsList';
import { toast } from '../utils/toast';
import { reportsAPI, locationsAPI, categoriesAPI } from '../services/api';
import '../styles/dashboard_air.css';
import '../styles/system_reports.css';

/**
 * Cosmetic-only mapping from a catalog report's `key` to one of the icon
 * glyphs this page already ships. Never affects what a card claims to be
 * able to do — title, description and formats all come straight off the
 * catalog entry. An unknown key just gets the default glyph.
 */
const ICON_BY_KEY = {
  'inventory-summary': 'inventory',
  'transaction': 'transaction',
  'location-performance': 'branch',
  'low-stock': 'lowstock',
  'warehouse-utilization': 'warehouse',
  'user-activity': 'user',
};

const FORMAT_LABELS = { pdf: 'PDF', csv: 'CSV' };

const formatsLabel = (formats) => {
  if (!Array.isArray(formats) || formats.length === 0) return 'No formats available';
  return formats.map((f) => FORMAT_LABELS[f] || f.toUpperCase()).join(' / ');
};

const ReportIcon = ({ type }) => {
  const cls = `sr-report-icon sr-report-icon-${type}`;
  switch (type) {
    case 'inventory':
      return (
        <div className={cls}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
            <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
            <line x1="12" y1="22.08" x2="12" y2="12" />
          </svg>
        </div>
      );
    case 'transaction':
      return (
        <div className={cls}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="9" cy="21" r="1" />
            <circle cx="20" cy="21" r="1" />
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
          </svg>
        </div>
      );
    case 'branch':
      return (
        <div className={cls}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
          </svg>
        </div>
      );
    case 'lowstock':
      return (
        <div className={cls}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <line x1="18" y1="20" x2="18" y2="10" />
            <line x1="12" y1="20" x2="12" y2="4" />
            <line x1="6" y1="20" x2="6" y2="14" />
          </svg>
        </div>
      );
    case 'warehouse':
      return (
        <div className={cls}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
          </svg>
        </div>
      );
    case 'user':
      return (
        <div className={cls}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
          </svg>
        </div>
      );
    default:
      return <div className={cls} />;
  }
};

const CardSkeleton = () => (
  <div className="sr-card sr-card--skeleton" aria-hidden="true">
    <div className="sr-skeleton-line sr-skeleton-line--title" />
    <div className="sr-skeleton-line sr-skeleton-line--desc" />
    <div className="sr-card-content">
      <div className="sr-skeleton-icon" />
      <div className="sr-skeleton-line sr-skeleton-line--formats" />
    </div>
    <div className="sr-skeleton-line sr-skeleton-line--btn" />
  </div>
);

const SystemReports = () => {
  const [catalog, setCatalog] = useState([]);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [catalogError, setCatalogError] = useState(null);

  const [runs, setRuns] = useState([]);
  const [runsLoading, setRunsLoading] = useState(true);

  const [locations, setLocations] = useState([]);
  const [categories, setCategories] = useState([]);
  const [users, setUsers] = useState([]);

  const [dialogReport, setDialogReport] = useState(null);
  const [dialogInitialValues, setDialogInitialValues] = useState(null);
  const [generating, setGenerating] = useState(false);

  const loadCatalog = useCallback(async () => {
    setCatalogLoading(true);
    setCatalogError(null);
    try {
      const res = await reportsAPI.getCatalog();
      setCatalog(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      setCatalogError(err?.message || 'Could not load the report catalog.');
      setCatalog([]);
    } finally {
      setCatalogLoading(false);
    }
  }, []);

  const loadRuns = useCallback(async () => {
    setRunsLoading(true);
    try {
      const res = await reportsAPI.getRuns();
      setRuns(Array.isArray(res?.data) ? res.data : []);
    } catch (err) {
      toast.error('Could not load recent reports', err?.message);
      setRuns([]);
    } finally {
      setRunsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCatalog();
    loadRuns();
    locationsAPI.getAll({ per_page: 500 })
      .then((res) => setLocations(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setLocations([]));
    categoriesAPI.getAll()
      .then((res) => setCategories(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setCategories([]));
    // Not usersAPI.getAll: that route needs user_account_management.read,
    // which only admin has, so an auditor or inventory analyst — both of whom
    // can run User Activity — got a 403 and a filter with nothing in it.
    reportsAPI.getFilterUsers()
      .then((res) => setUsers(Array.isArray(res?.data) ? res.data : []))
      .catch((err) => {
        setUsers([]);
        toast.error('Could not load the user filter', err?.message);
      });
  }, [loadCatalog, loadRuns]);

  const openGenerate = (report) => {
    setDialogInitialValues(null);
    setDialogReport(report);
  };

  const closeDialog = () => {
    setDialogReport(null);
    setDialogInitialValues(null);
  };

  const handleRerun = (run) => {
    const report = catalog.find((r) => r.key === run.report_key);
    if (!report) {
      toast.error('That report is no longer available', 'It may have been removed from the catalog.');
      return;
    }
    setDialogInitialValues({ format: run.format, parameters: run.parameters || {} });
    setDialogReport(report);
  };

  const handleGenerate = async ({ reportKey, format, parameters }) => {
    setGenerating(true);
    try {
      await reportsAPI.generate({ reportKey, format, parameters });
      toast.success('Report generated');
      closeDialog();
      await loadRuns();
    } catch (err) {
      toast.error('Could not generate the report', err?.message || 'Please try again.');
    } finally {
      setGenerating(false);
    }
  };

  const hasCards = !catalogLoading && !catalogError && catalog.length > 0;
  const showEmpty = !catalogLoading && !catalogError && catalog.length === 0;

  return (
    <AdminLayout>
      <div className="ak-page sr-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Insights · Reports</p>
              <h1 className="ak-title">Reports</h1>
              <p className="ak-sub">Generate and view system-wide inventory and transaction reports.</p>
            </div>
          </div>
        </header>

        {catalogError && (
          <div className="sr-inline-error" role="alert">
            {catalogError}
            <button type="button" className="ak-btn ak-btn--secondary" onClick={loadCatalog}>Retry</button>
          </div>
        )}

        {catalogLoading && (
          <div className="sr-cards-grid" aria-busy="true">
            {[0, 1, 2].map((i) => <CardSkeleton key={i} />)}
          </div>
        )}

        {showEmpty && (
          <div className="sr-empty-state">
            No reports are available for your account yet.
          </div>
        )}

        {hasCards && (
          <div className="sr-cards-grid">
            {catalog.map((r) => (
              <div className="sr-card" key={r.key}>
                <h3 className="sr-card-title">{r.title}</h3>
                <p className="sr-card-desc">{r.description}</p>
                <div className="sr-card-content">
                  <ReportIcon type={ICON_BY_KEY[r.key] || 'default'} />
                  <div className="sr-card-format-block">
                    <span className="sr-format-label">Available Formats</span>
                    <span className="sr-format-types">{formatsLabel(r.formats)}</span>
                  </div>
                </div>
                <div className="sr-card-actions">
                  <button type="button" className="sr-btn-generate" onClick={() => openGenerate(r)}>
                    Generate Report
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="sr-recent-card">
          <h3 className="sr-recent-title">Recent Reports</h3>
          <p className="sr-recent-subtitle">Previously generated reports</p>
          <RecentReportsList runs={runs} loading={runsLoading} onRerun={handleRerun} />
        </div>
      </div>

      {dialogReport && (
        <GenerateReportDialog
          report={dialogReport}
          locations={locations}
          categories={categories}
          users={users}
          initialValues={dialogInitialValues}
          generating={generating}
          onGenerate={handleGenerate}
          onClose={closeDialog}
        />
      )}
    </AdminLayout>
  );
};

export default SystemReports;
