import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import { permissionsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ROLE_COLUMNS, ACTIONS } from '../utils/permissions';
import '../styles/rbac_console.css';

const CHANGE_TYPES = ['role_default', 'user_override', 'override_cleared'];

const roleName = (roleId) => {
  if (!roleId) return '—';
  const r = ROLE_COLUMNS.find((c) => c.id === Number(roleId));
  return r ? r.name : `Role #${roleId}`;
};

const formatDate = (d) => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleString('en-US', {
      month: '2-digit', day: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  } catch (_) {
    return d;
  }
};

const changeBadgeClass = (type) => {
  if (type === 'role_default') return 'rcx-badge rcx-badge--role';
  if (type === 'user_override') return 'rcx-badge rcx-badge--user';
  return 'rcx-badge rcx-badge--clear';
};

const BoolBadge = ({ val }) => {
  if (val == null) return <span className="rcx-muted">—</span>;
  return (
    <span className={`rcx-bool ${val ? 'rcx-bool--granted' : 'rcx-bool--denied'}`}>
      {val ? 'Granted' : 'Denied'}
    </span>
  );
};

const PermissionAuditLog = () => {
  const [rows, setRows]         = useState([]);
  const [pagination, setPagination] = useState({ total: 0, last_page: 1, per_page: 20 });
  const [page, setPage]         = useState(1);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState(null);

  // Filters
  const [filterType, setFilterType]     = useState('');
  const [filterFeature, setFilterFeature] = useState('');
  const [filterAction, setFilterAction]   = useState('');
  const [filterFrom, setFilterFrom]       = useState('');
  const [filterTo, setFilterTo]           = useState('');
  const [perPage] = useState(20);

  const load = useCallback(async (p = 1) => {
    try {
      setLoading(true);
      setError(null);
      const params = { page: p, per_page: perPage };
      if (filterType)    params.change_type = filterType;
      if (filterFeature) params.feature     = filterFeature;
      if (filterAction)  params.action      = filterAction;
      if (filterFrom)    params.date_from   = filterFrom;
      if (filterTo)      params.date_to     = filterTo;

      const res = await permissionsAPI.getAuditLogs(params);
      const d   = res?.data?.data || res?.data || [];
      const pag = res?.data?.pagination ||
                  { total: res?.data?.total || 0, last_page: res?.data?.last_page || 1, per_page: perPage };

      setRows(Array.isArray(d) ? d : []);
      setPagination(pag);
    } catch (err) {
      setError(err.message || 'Failed to load audit log.');
      toast.error(err.message || 'Failed to load audit log.');
    } finally {
      setLoading(false);
    }
  }, [filterType, filterFeature, filterAction, filterFrom, filterTo, perPage]);

  useEffect(() => {
    setPage(1);
    load(1);
  }, [load]); // eslint-disable-line react-hooks/exhaustive-deps

  const handlePageChange = (p) => {
    setPage(p);
    load(p);
  };

  const handleFilterSubmit = (e) => {
    e.preventDefault();
    setPage(1);
    load(1);
  };

  const handleReset = () => {
    setFilterType('');
    setFilterFeature('');
    setFilterAction('');
    setFilterFrom('');
    setFilterTo('');
    setPage(1);
  };

  return (
    <AdminLayout>
      <div className="rbac-console">
        <header className="rcx-header">
          <div className="rcx-header-row">
            <div>
              <p className="rcx-eyebrow">Access Control · Audit Trail</p>
              <h1 className="rcx-title">Permission Audit Log</h1>
              <p className="rcx-sub">
                Read-only history of every permission change made to roles and individual users.
              </p>
            </div>
          </div>
        </header>

        {/* Filters */}
        <form className="rcx-filters" onSubmit={handleFilterSubmit}>
          <div className="rcx-field">
            <label className="rcx-label">Change type</label>
            <select className="rcx-select" value={filterType} onChange={(e) => setFilterType(e.target.value)} style={{ minWidth: 160 }}>
              <option value="">All types</option>
              {CHANGE_TYPES.map((t) => (
                <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
              ))}
            </select>
          </div>

          <div className="rcx-field">
            <label className="rcx-label">Feature</label>
            <input className="rcx-input" type="text" value={filterFeature}
              onChange={(e) => setFilterFeature(e.target.value)}
              placeholder="e.g. user_account_management" style={{ minWidth: 230 }} />
          </div>

          <div className="rcx-field">
            <label className="rcx-label">Action</label>
            <select className="rcx-select" value={filterAction} onChange={(e) => setFilterAction(e.target.value)} style={{ minWidth: 130 }}>
              <option value="">All actions</option>
              {ACTIONS.map((a) => (
                <option key={a} value={a}>{a}</option>
              ))}
            </select>
          </div>

          <div className="rcx-field">
            <label className="rcx-label">From date</label>
            <input className="rcx-input" type="date" value={filterFrom} onChange={(e) => setFilterFrom(e.target.value)} style={{ minWidth: 150 }} />
          </div>

          <div className="rcx-field">
            <label className="rcx-label">To date</label>
            <input className="rcx-input" type="date" value={filterTo} onChange={(e) => setFilterTo(e.target.value)} style={{ minWidth: 150 }} />
          </div>

          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <button type="submit" className="rcx-btn rcx-btn--primary" disabled={loading} style={{ minWidth: 92 }}>
              {loading ? 'Loading…' : 'Search'}
            </button>
            <button type="button" className="rcx-btn rcx-btn--ghost" onClick={handleReset} disabled={loading}>
              Reset
            </button>
          </div>
        </form>

        {error && <div className="rcx-state rcx-state--error" style={{ marginBottom: '1rem' }}>Error: {error}</div>}

        {/* Table */}
        <div className="rcx-matrix-wrap">
          <table className="rcx-table" style={{ minWidth: 900 }}>
            <thead>
              <tr>
                <th>When</th>
                <th>Changed By</th>
                <th>Target</th>
                <th>Permission</th>
                <th>Change</th>
                <th style={{ textAlign: 'center' }}>Old</th>
                <th style={{ textAlign: 'center' }}>New</th>
                <th style={{ minWidth: 180 }}>Reason</th>
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr><td colSpan={8} style={{ textAlign: 'center', padding: '2.5rem' }} className="rcx-muted">Loading<span className="rcx-loading-dots" /></td></tr>
              )}
              {!loading && rows.length === 0 && (
                <tr><td colSpan={8} style={{ textAlign: 'center', padding: '2.5rem' }} className="rcx-muted">No audit log entries found.</td></tr>
              )}
              {!loading && rows.map((row, i) => {
                const feature = row.permission?.feature || row.feature || '—';
                const action  = row.permission?.action  || row.action  || '—';
                const changedBy = row.changedBy
                  ? (row.changedBy.first_name && row.changedBy.last_name
                      ? `${row.changedBy.first_name} ${row.changedBy.last_name}`
                      : row.changedBy.username || row.changedBy.email || '—')
                  : '—';
                const targetUser = row.targetUser
                  ? (row.targetUser.first_name && row.targetUser.last_name
                      ? `${row.targetUser.first_name} ${row.targetUser.last_name}`
                      : row.targetUser.username || row.targetUser.email || '—')
                  : null;
                const target = targetUser || (row.target_role_id ? roleName(row.target_role_id) : '—');

                return (
                  <tr key={row.id || i}>
                    <td className="rcx-mono rcx-muted" style={{ whiteSpace: 'nowrap' }}>{formatDate(row.changed_at)}</td>
                    <td>{changedBy}</td>
                    <td>{target}</td>
                    <td className="rcx-mono">{feature}.{action}</td>
                    <td><span className={changeBadgeClass(row.change_type)}>{row.change_type?.replace(/_/g, ' ') || '—'}</span></td>
                    <td style={{ textAlign: 'center' }}><BoolBadge val={row.old_value} /></td>
                    <td style={{ textAlign: 'center' }}><BoolBadge val={row.new_value} /></td>
                    <td className={row.reason ? '' : 'rcx-muted'}>{row.reason || '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {!loading && (
          <div style={{ marginTop: '1rem' }}>
            <Pagination
              currentPage={page}
              totalPages={pagination.last_page || 1}
              totalItems={pagination.total || 0}
              pageSize={pagination.per_page || perPage}
              onPageChange={handlePageChange}
              label="entries"
            />
          </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default PermissionAuditLog;
