import React, { useState, useEffect, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import { permissionsAPI } from '../services/api';
import { toast } from '../utils/toast';
import { ROLE_COLUMNS, ACTIONS } from '../utils/permissions';
import '../styles/dashboard_air.css';

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

const changeBadge = (type) => {
  const styles = {
    role_default:     { bg: '#ede9fe', color: '#5b21b6' },
    user_override:    { bg: '#fef3c7', color: '#92400e' },
    override_cleared: { bg: '#f1f5f9', color: '#475569' },
  };
  const s = styles[type] || { bg: '#f1f5f9', color: '#475569' };
  return (
    <span
      style={{
        fontSize: '0.72rem', fontWeight: 600, padding: '2px 8px',
        borderRadius: 4, background: s.bg, color: s.color,
        textTransform: 'uppercase', letterSpacing: '0.04em',
        whiteSpace: 'nowrap',
      }}
    >
      {type?.replace(/_/g, ' ') || '—'}
    </span>
  );
};

const boolBadge = (val) => {
  if (val == null) return <span style={{ color: 'var(--text-muted)' }}>—</span>;
  return (
    <span style={{ color: val ? '#059669' : '#dc2626', fontWeight: 600 }}>
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
      <div className="cb-page">
        <div className="cb-page-header">
          <div>
            <h1>Permission Audit Log</h1>
            <p>Read-only history of all permission changes made to roles and individual users.</p>
          </div>
        </div>

        {/* Filters */}
        <form
          onSubmit={handleFilterSubmit}
          style={{
            display: 'flex', flexWrap: 'wrap', gap: '0.75rem',
            alignItems: 'flex-end', marginBottom: '1.25rem',
            padding: '1rem', background: 'var(--bg-surface, #f8fafc)',
            borderRadius: 8, border: '1px solid var(--border-color, #e2e8f0)',
          }}
        >
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)' }}>Change type</label>
            <select
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
              style={{ padding: '0.35rem 0.6rem', borderRadius: 5, border: '1px solid var(--border-color, #e2e8f0)', background: 'var(--bg-card, #fff)', minWidth: 150 }}
            >
              <option value="">All types</option>
              {CHANGE_TYPES.map((t) => (
                <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
              ))}
            </select>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)' }}>Feature</label>
            <input
              type="text"
              value={filterFeature}
              onChange={(e) => setFilterFeature(e.target.value)}
              placeholder="e.g. user_account_management"
              style={{ padding: '0.35rem 0.6rem', borderRadius: 5, border: '1px solid var(--border-color, #e2e8f0)', background: 'var(--bg-card, #fff)', minWidth: 200 }}
            />
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)' }}>Action</label>
            <select
              value={filterAction}
              onChange={(e) => setFilterAction(e.target.value)}
              style={{ padding: '0.35rem 0.6rem', borderRadius: 5, border: '1px solid var(--border-color, #e2e8f0)', background: 'var(--bg-card, #fff)', minWidth: 120 }}
            >
              <option value="">All actions</option>
              {ACTIONS.map((a) => (
                <option key={a} value={a}>{a}</option>
              ))}
            </select>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)' }}>From date</label>
            <input
              type="date"
              value={filterFrom}
              onChange={(e) => setFilterFrom(e.target.value)}
              style={{ padding: '0.35rem 0.6rem', borderRadius: 5, border: '1px solid var(--border-color, #e2e8f0)', background: 'var(--bg-card, #fff)' }}
            />
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label style={{ fontSize: '0.78rem', fontWeight: 600, color: 'var(--text-muted)' }}>To date</label>
            <input
              type="date"
              value={filterTo}
              onChange={(e) => setFilterTo(e.target.value)}
              style={{ padding: '0.35rem 0.6rem', borderRadius: 5, border: '1px solid var(--border-color, #e2e8f0)', background: 'var(--bg-card, #fff)' }}
            />
          </div>

          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <button type="submit" className="cb-btn-primary" disabled={loading} style={{ minWidth: 80 }}>
              {loading ? 'Loading…' : 'Search'}
            </button>
            <button type="button" className="cb-btn-secondary" onClick={handleReset} disabled={loading}>
              Reset
            </button>
          </div>
        </form>

        {error && <div className="cb-error" style={{ marginBottom: '1rem' }}>Error: {error}</div>}

        {/* Table */}
        <div style={{ overflowX: 'auto' }}>
          <table className="cb-table" style={{ minWidth: 880 }}>
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
                <tr>
                  <td colSpan={8} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
                    Loading…
                  </td>
                </tr>
              )}
              {!loading && rows.length === 0 && (
                <tr>
                  <td colSpan={8} style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>
                    No audit log entries found.
                  </td>
                </tr>
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
                    <td style={{ whiteSpace: 'nowrap', fontSize: '0.82rem' }}>
                      {formatDate(row.changed_at)}
                    </td>
                    <td style={{ fontSize: '0.85rem' }}>{changedBy}</td>
                    <td style={{ fontSize: '0.85rem' }}>{target}</td>
                    <td style={{ fontFamily: 'monospace', fontSize: '0.82rem' }}>
                      {feature}.{action}
                    </td>
                    <td>{changeBadge(row.change_type)}</td>
                    <td style={{ textAlign: 'center' }}>{boolBadge(row.old_value)}</td>
                    <td style={{ textAlign: 'center' }}>{boolBadge(row.new_value)}</td>
                    <td style={{ fontSize: '0.82rem', color: row.reason ? 'inherit' : 'var(--text-muted)' }}>
                      {row.reason || '—'}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
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
