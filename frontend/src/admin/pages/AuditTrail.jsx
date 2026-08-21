import React, { useCallback, useEffect, useMemo, useState } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Pagination from '../components/Pagination';
import { auditAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/airking_ds.css';
import '../styles/audit_trail.css';

const PAGE_SIZE = 15;
const SKIP_FIELDS = new Set(['created_at', 'updated_at', 'deleted_at', 'password_hash', 'remember_token', 'otp_code']);

function formatTimestamp(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
  });
}

function buildSummary(action, oldValues, newValues, recordId) {
  const skip = SKIP_FIELDS;
  if (action === 'unauthorized_access') {
    return 'Unrecognized account attempted to access the system.';
  }
  if (action === 'update' && newValues && typeof newValues === 'object') {
    const changed = Object.keys(newValues).filter((k) => !skip.has(k));
    if (!changed.length) return 'Record updated';
    return `${changed.length} field(s) changed`;
  }
  if (action === 'create') return 'Record created';
  if (action === 'delete') return recordId != null ? `Record #${recordId} removed` : 'Record removed';
  if (action === 'login') return 'User signed in';
  if (action === 'logout') return 'User signed out';
  if (action === 'login_failed') {
    return (newValues && newValues.reason) || 'Sign-in attempt failed';
  }
  return action ? String(action).replace(/_/g, ' ') : '—';
}

function actionDisplayLabel(action) {
  if (action === 'unauthorized_access') return 'UNAUTHORIZED';
  if (action === 'login_failed') return 'FAILED LOGIN';
  return action ? action.toUpperCase() : '—';
}

function prettyJson(value) {
  if (value == null) return null;
  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2);
    } catch {
      return value;
    }
  }
  try {
    const cleaned = Object.fromEntries(
      Object.entries(value).filter(([k]) => !SKIP_FIELDS.has(k))
    );
    return JSON.stringify(cleaned, null, 2);
  } catch {
    return String(value);
  }
}

function hasValues(value) {
  if (value == null) return false;
  if (typeof value === 'object') return Object.keys(value).filter((k) => !SKIP_FIELDS.has(k)).length > 0;
  return String(value).trim().length > 0;
}

const AuditTrail = () => {
  const [activeTab, setActiveTab] = useState('activity');
  const [logs, setLogs] = useState([]);
  const [sessions, setSessions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [sessionsLoading, setSessionsLoading] = useState(false);
  const [error, setError] = useState('');
  const [sessionsError, setSessionsError] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [sessionsPage, setSessionsPage] = useState(1);
  const [expandedIds, setExpandedIds] = useState(new Set());

  const [sessionFilters, setSessionFilters] = useState({
    email: '',
    status: 'all',
    ip: '',
  });
  const [appliedSessionFilters, setAppliedSessionFilters] = useState({
    email: '',
    status: 'all',
    ip: '',
  });

  const loadActivity = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await auditAPI.getTrail({ per_page: 200 });
      const payload = Array.isArray(res?.data?.data)
        ? res.data.data
        : Array.isArray(res?.data)
          ? res.data
          : [];
      setLogs(payload);
    } catch (e) {
      const msg = e.message || 'Failed to load activity log.';
      setError(msg);
      toast.error(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  const loadSessions = useCallback(async (filters = appliedSessionFilters) => {
    setSessionsLoading(true);
    setSessionsError('');
    try {
      const res = await auditAPI.getLoginSessions({
        per_page: 100,
        email: filters.email || undefined,
        status: filters.status && filters.status !== 'all' ? filters.status : undefined,
        ip: filters.ip || undefined,
      });
      const payload = Array.isArray(res?.data) ? res.data : [];
      setSessions(payload);
    } catch (e) {
      const msg = e.message || 'Failed to load login sessions.';
      setSessionsError(msg);
      toast.error(msg);
    } finally {
      setSessionsLoading(false);
    }
  }, [appliedSessionFilters]);

  useEffect(() => {
    loadActivity();
  }, [loadActivity]);

  useEffect(() => {
    if (activeTab === 'sessions') {
      loadSessions();
    }
  }, [activeTab, loadSessions]);

  const refresh = () => {
    if (activeTab === 'activity') loadActivity();
    else loadSessions(appliedSessionFilters);
  };

  const activityRows = useMemo(() => logs.map((log, index) => {
    const action = (log?.action || '').toLowerCase();
    const isUnauthorized = action === 'unauthorized_access';
    const name =
      `${log?.user?.first_name || ''} ${log?.user?.last_name || ''}`.trim() ||
      log?.user?.username ||
      null;
    const email = isUnauthorized
      ? 'unknown'
      : (log?.user?.email || name || 'System');
    const safeNewValues = isUnauthorized
      ? {
          attempt_type: 'unrecognized_account',
          reason: 'Unrecognized account attempted to access the system.',
          attempted_email: 'unknown',
        }
      : (log?.new_values || null);
    return {
      id: log?.audit_id ?? log?.id ?? index,
      email,
      action,
      actionLabel: actionDisplayLabel(action),
      table: log?.table_name || (isUnauthorized ? 'security' : '—'),
      recordId: isUnauthorized ? '—' : (log?.record_id ?? '—'),
      summary: buildSummary(action, log?.old_values, safeNewValues, log?.record_id),
      timestamp: formatTimestamp(log?.created_at),
      oldValues: log?.old_values || null,
      newValues: safeNewValues,
      isUnauthorized,
    };
  }), [logs]);

  const totalPages = Math.ceil(activityRows.length / PAGE_SIZE) || 1;
  const pagedActivity = activityRows.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  const sessionsTotalPages = Math.ceil(sessions.length / PAGE_SIZE) || 1;
  const pagedSessions = sessions.slice((sessionsPage - 1) * PAGE_SIZE, sessionsPage * PAGE_SIZE);

  const toggleExpanded = useCallback((id) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  const applySessionFilters = (e) => {
    e.preventDefault();
    const next = { ...sessionFilters };
    setAppliedSessionFilters(next);
    setSessionsPage(1);
    loadSessions(next);
  };

  return (
    <AdminLayout>
      <div className="ak-page at-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <h1 className="ak-title">Audit trail</h1>
              <p className="ak-sub">
                Review data changes, unauthorized access attempts, and user login sessions — including IP address, browser/device, and login/logout times.
              </p>
            </div>
            <div className="ak-header-aside ak-actions">
              <button type="button" className="ak-btn ak-btn--sm" onClick={refresh} disabled={loading || sessionsLoading}>
                Refresh
              </button>
            </div>
          </div>
        </header>

        <div className="at-view-toggle" role="tablist" aria-label="Audit trail views">
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === 'activity'}
            className={`at-view-toggle__btn${activeTab === 'activity' ? ' is-active' : ''}`}
            onClick={() => setActiveTab('activity')}
          >
            Activity log
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === 'sessions'}
            className={`at-view-toggle__btn${activeTab === 'sessions' ? ' is-active' : ''}`}
            onClick={() => setActiveTab('sessions')}
          >
            Login sessions
          </button>
        </div>

        {activeTab === 'activity' && (
          <div className="at-table-card">
            <div className="at-table-top">
              <h3>Activity log</h3>
            </div>

            <div className="at-table-wrapper">
              <table className="at-table">
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Action</th>
                    <th>Table</th>
                    <th>Record ID</th>
                    <th>Summary</th>
                    <th>Timestamp</th>
                    <th>Trail</th>
                  </tr>
                </thead>
                <tbody>
                  {loading && (
                    <tr><td colSpan={7}><Loader label="Loading activity log" /></td></tr>
                  )}
                  {!loading && error && (
                    <tr><td colSpan={7} className="at-cell-center at-cell-error">{error}</td></tr>
                  )}
                  {!loading && !error && activityRows.length === 0 && (
                    <tr><td colSpan={7} className="at-cell-center">No activity records found.</td></tr>
                  )}

                  {!loading && !error && pagedActivity.map((row) => {
                    const expanded = expandedIds.has(row.id);
                    return (
                      <React.Fragment key={row.id}>
                        <tr className={row.isUnauthorized ? 'at-row--unauthorized' : undefined}>
                          <td className="at-cell-user">
                            {row.email}
                            {row.isUnauthorized && (
                              <span className="at-unauthorized-tag">Unauthorized attempt</span>
                            )}
                          </td>
                          <td>
                            <span className={`at-action-badge at-action-badge--${row.isUnauthorized ? 'unauthorized' : (row.action || 'other')}`}>
                              {row.actionLabel}
                            </span>
                          </td>
                          <td className="at-cell-mono">{row.table}</td>
                          <td className="at-cell-mono">{row.recordId}</td>
                          <td>{row.summary}</td>
                          <td className="at-cell-timestamp">{row.timestamp}</td>
                          <td>
                            <button
                              type="button"
                              className="at-trail-toggle"
                              onClick={() => toggleExpanded(row.id)}
                              aria-expanded={expanded}
                            >
                              {expanded ? 'Hide before / after' : 'View before / after'}
                            </button>
                          </td>
                        </tr>
                        {expanded && (
                          <tr className="at-trail-detail-row">
                            <td colSpan={7}>
                              <div className="at-trail-panel">
                                <div className="at-trail-block">
                                  <h4>Previous values</h4>
                                  {hasValues(row.oldValues) ? (
                                    <pre className="at-trail-json">{prettyJson(row.oldValues)}</pre>
                                  ) : (
                                    <p className="at-trail-empty">None (create or no prior state captured)</p>
                                  )}
                                </div>
                                <div className="at-trail-block">
                                  <h4>New values</h4>
                                  {hasValues(row.newValues) ? (
                                    <pre className="at-trail-json">{prettyJson(row.newValues)}</pre>
                                  ) : (
                                    <p className="at-trail-empty">None (delete or no new state captured)</p>
                                  )}
                                </div>
                              </div>
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <Pagination
              currentPage={currentPage}
              totalPages={totalPages}
              onPageChange={setCurrentPage}
              totalItems={activityRows.length}
              pageSize={PAGE_SIZE}
              label="log entries"
            />
          </div>
        )}

        {activeTab === 'sessions' && (
          <>
            <form className="at-filter-bar" onSubmit={applySessionFilters}>
              <div className="ak-field">
                <label className="ak-label" htmlFor="at-filter-email">Email</label>
                <input
                  id="at-filter-email"
                  className="ak-input"
                  type="text"
                  placeholder="e.g. admin@airking.com"
                  value={sessionFilters.email}
                  onChange={(e) => setSessionFilters((f) => ({ ...f, email: e.target.value }))}
                />
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="at-filter-status">Status</label>
                <select
                  id="at-filter-status"
                  className="ak-select"
                  value={sessionFilters.status}
                  onChange={(e) => setSessionFilters((f) => ({ ...f, status: e.target.value }))}
                >
                  <option value="all">All</option>
                  <option value="active">Active</option>
                  <option value="logged_out">Logged out</option>
                </select>
              </div>
              <div className="ak-field">
                <label className="ak-label" htmlFor="at-filter-ip">IP address</label>
                <input
                  id="at-filter-ip"
                  className="ak-input"
                  type="text"
                  placeholder="e.g. 127.0.0.1"
                  value={sessionFilters.ip}
                  onChange={(e) => setSessionFilters((f) => ({ ...f, ip: e.target.value }))}
                />
              </div>
              <div className="at-filter-bar__actions">
                <button type="submit" className="ak-btn ak-btn--primary ak-btn--sm">
                  Apply filters
                </button>
              </div>
            </form>

            <div className="at-table-card">
              <div className="at-table-top">
                <h3>Login sessions</h3>
              </div>

              <div className="at-table-wrapper">
                <table className="at-table">
                  <thead>
                    <tr>
                      <th>User</th>
                      <th>Status</th>
                      <th>Login time</th>
                      <th>Logout time</th>
                      <th>Duration</th>
                      <th>IP address</th>
                      <th>Device / browser</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sessionsLoading && (
                      <tr><td colSpan={8}><Loader label="Loading login sessions" /></td></tr>
                    )}
                    {!sessionsLoading && sessionsError && (
                      <tr><td colSpan={8} className="at-cell-center at-cell-error">{sessionsError}</td></tr>
                    )}
                    {!sessionsLoading && !sessionsError && sessions.length === 0 && (
                      <tr><td colSpan={8} className="at-cell-center">No login sessions found.</td></tr>
                    )}
                    {!sessionsLoading && !sessionsError && pagedSessions.map((s) => (
                      <tr key={s.id}>
                        <td>
                          <div className="at-operator-cell">
                            <span className="at-operator-name">{s.email || '—'}</span>
                            {s.display_name && (
                              <span className="at-operator-role">{s.display_name}</span>
                            )}
                          </div>
                        </td>
                        <td>
                          <span className={`at-status-badge at-status-badge--${s.status}`}>
                            {s.status_label}
                          </span>
                        </td>
                        <td className="at-cell-timestamp">{formatTimestamp(s.login_at)}</td>
                        <td className="at-cell-timestamp">{s.logout_at ? formatTimestamp(s.logout_at) : '—'}</td>
                        <td>{s.duration_label || '—'}</td>
                        <td className="at-cell-ip">{s.ip_address || '—'}</td>
                        <td>
                          <div className="at-device-cell">
                            <span>{s.device_label || '—'}</span>
                            {s.platform && <span className="at-device-platform">{s.platform}</span>}
                          </div>
                        </td>
                        <td className="at-cell-notes">{s.notes || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <Pagination
                currentPage={sessionsPage}
                totalPages={sessionsTotalPages}
                onPageChange={setSessionsPage}
                totalItems={sessions.length}
                pageSize={PAGE_SIZE}
                label="sessions"
              />
            </div>
          </>
        )}
      </div>
    </AdminLayout>
  );
};

export default AuditTrail;
