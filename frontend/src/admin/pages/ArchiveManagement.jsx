import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Link } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { getApiBaseUrl, getToken } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { getRoleKey, ROLES } from '../utils/roles';
import { toast } from '../utils/toast';
import { confirmAction } from '../utils/confirm';
import '../styles/archive_management.css';
import '../styles/archive_settings.css';
import DateInput from '../components/DateInput';

const MODULES = [
  { key: 'products',           label: 'Products' },
  { key: 'locations',          label: 'Locations' },
  { key: 'users',              label: 'Users' },
  { key: 'customers',          label: 'Customers' },
  { key: 'suppliers',          label: 'Suppliers' },
  { key: 'brands',             label: 'Brands' },
  { key: 'categories',         label: 'Categories' },
  { key: 'models',             label: 'Models' },
  { key: 'units',              label: 'Units' },
  { key: 'purchase_orders',    label: 'Purchase Orders' },
  { key: 'purchase_requests',  label: 'Purchase Requests' },
  { key: 'transfers',          label: 'Transfers' },
  { key: 'adjustments',        label: 'Adjustments' },
  { key: 'issuances',          label: 'Issuances' },
  { key: 'budget_allocations', label: 'Budget Allocations' },
];

const PER_PAGE = 15;

const ArchiveManagement = () => {
  const { user, can } = useAuth();
  const roleKey = getRoleKey(user || {});
  const isAdmin = roleKey === ROLES.ADMIN;
  // Restore and force-delete from the archive are admin-only.
  const canRestore     = isAdmin || (typeof can === 'function' && can('data_archiving', 'update'));
  const canForceDelete = isAdmin || (typeof can === 'function' && can('data_archiving', 'archive'));

  const [summary, setSummary]         = useState({});
  const [activeModule, setActiveModule] = useState('products');
  const [records, setRecords]         = useState([]);
  const [loading, setLoading]         = useState(false);
  const [page, setPage]               = useState(1);
  const [totalPages, setTotalPages]   = useState(1);
  const [totalItems, setTotalItems]   = useState(0);
  const [search, setSearch]           = useState('');
  const [deletedFrom, setDeletedFrom] = useState('');
  const [deletedTo, setDeletedTo]     = useState('');

  const searchRef = useRef(null);

  const authHeaders = useCallback(() => {
    const token = getToken();
    return {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };
  }, []);

  const apiUrl = (path) => `${getApiBaseUrl()}${path}`;

  // ── Summary ───────────────────────────────────────────────────────────────
  const loadSummary = useCallback(async () => {
    try {
      const res = await fetch(apiUrl('/archive/summary'), { headers: authHeaders() });
      const data = await res.json();
      setSummary(data.data?.modules ?? {});
    } catch {
      // silently ignore — badges simply won't appear
    }
  }, [authHeaders]);

  useEffect(() => { loadSummary(); }, [loadSummary]);

  // ── Records ───────────────────────────────────────────────────────────────
  const loadRecords = useCallback(async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) });
      if (search)      params.append('search',       search);
      if (deletedFrom) params.append('deleted_from', deletedFrom);
      if (deletedTo)   params.append('deleted_to',   deletedTo);

      const res = await fetch(apiUrl(`/archive/${activeModule}?${params}`), { headers: authHeaders() });
      const data = await res.json();
      setRecords(Array.isArray(data.data) ? data.data : []);
      setTotalPages(data.pagination?.last_page ?? 1);
      setTotalItems(data.pagination?.total ?? 0);
    } catch (e) {
      toast.error('Failed to load archived records.');
    } finally {
      setLoading(false);
    }
  }, [activeModule, page, search, deletedFrom, deletedTo, authHeaders]);

  useEffect(() => { loadRecords(); }, [loadRecords]);

  // ── Restore ───────────────────────────────────────────────────────────────
  const handleRestore = async (module, id) => {
    if (!(await confirmAction({ title: 'Restore this record?', text: 'It will become active again.', confirmText: 'Restore', tone: 'primary' }))) return;
    try {
      const res = await fetch(
        apiUrl(`/archive/${module}/${id}/restore`),
        { method: 'POST', headers: authHeaders() }
      );
      const data = await res.json();
      if (data.success) {
        toast.success(data.message || 'Record restored.');
        loadRecords();
        setSummary(prev => ({
          ...prev,
          [module]: { ...prev[module], count: Math.max(0, (prev[module]?.count ?? 1) - 1) },
        }));
      } else {
        toast.error(data.message ?? 'Restore failed.');
      }
    } catch {
      toast.error('Network error. Please try again.');
    }
  };

  // ── Force delete ──────────────────────────────────────────────────────────
  const handleForceDelete = async (module, id) => {
    if (!(await confirmAction({ title: 'Permanently delete this record?', text: 'This action cannot be undone. The record will be completely removed from the database and cannot be recovered.', confirmText: 'Continue', tone: 'danger' }))) return;
    if (!(await confirmAction({ title: 'Are you absolutely sure?', text: 'This is permanent.', confirmText: 'Delete permanently', tone: 'danger' }))) return;

    try {
      const res = await fetch(
        apiUrl(`/archive/${module}/${id}/force`),
        { method: 'DELETE', headers: authHeaders() }
      );
      const data = await res.json();
      if (data.success) {
        toast.success('Record permanently deleted.');
        loadRecords();
        setSummary(prev => ({
          ...prev,
          [module]: { ...prev[module], count: Math.max(0, (prev[module]?.count ?? 1) - 1) },
        }));
      } else {
        toast.error(data.message ?? 'Permanent delete failed.');
      }
    } catch {
      toast.error('Network error. Please try again.');
    }
  };

  // ── Release identifiers (users only) ──────────────────────────────────────
  /**
   * Frees an archived account's login email, username and recovery address for
   * a new account without deleting the account. This is what "Delete
   * Permanently" was being used for, and it is the safe way to do it: the row
   * and everything attributed to it stay put.
   */
  const handleReleaseIdentifiers = async (id, displayName) => {
    if (!(await confirmAction({
      title: "Release this account's email and username?",
      text: `${displayName}'s login email, username and recovery email will be retired so a `
        + 'new account can use them. The account stays archived and keeps its history. '
        + 'This cannot be undone.',
      confirmText: 'Release',
      tone: 'danger',
    }))) return;

    try {
      const res = await fetch(
        apiUrl(`/users/${id}/release-identifiers`),
        { method: 'POST', headers: authHeaders() }
      );
      const data = await res.json();
      if (data.success) {
        toast.success(data.message || 'Email and username released.');
        loadRecords();
      } else {
        toast.error(data.message ?? 'Release failed.');
      }
    } catch {
      toast.error('Network error. Please try again.');
    }
  };

  // ── Restore all ───────────────────────────────────────────────────────────
  const handleRestoreAll = async () => {
    if (!(await confirmAction({ title: `Restore all ${totalItems} archived ${activeModule} records?`, confirmText: 'Restore all', tone: 'primary' }))) return;
    try {
      const res = await fetch(
        apiUrl(`/archive/${activeModule}/restore-all`),
        { method: 'POST', headers: authHeaders() }
      );
      const data = await res.json();
      if (data.success) {
        toast.success(data.message || 'All records restored.');
        loadRecords();
        loadSummary();
      } else {
        toast.error(data.message ?? 'Restore all failed.');
      }
    } catch {
      toast.error('Network error. Please try again.');
    }
  };

  // ── Helpers ───────────────────────────────────────────────────────────────
  const totalArchived = Object.values(summary).reduce((s, m) => s + (m?.count ?? 0), 0);

  const switchModule = (key) => {
    setActiveModule(key);
    setPage(1);
    setSearch('');
    setDeletedFrom('');
    setDeletedTo('');
    if (searchRef.current) searchRef.current.value = '';
  };

  const clearFilters = () => {
    setSearch('');
    setDeletedFrom('');
    setDeletedTo('');
    setPage(1);
  };

  const activeLabel = MODULES.find(m => m.key === activeModule)?.label ?? '';

  return (
    <AdminLayout>
      <div className="ak-page am-content">

        {/* Page Header */}
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">System · Archive</p>
              <h1 className="ak-title">Archive Management</h1>
              <p className="ak-sub">Browse, restore, or permanently remove soft-deleted records across all modules.</p>
            </div>
          </div>
        </header>

        {/* Page-level navigation tabs */}
        <div className="am-page-tabs">
          <Link
            to="/admin/archive"
            className="am-page-tab am-page-tab--active"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
              <polyline points="21 8 21 21 3 21 3 8" /><rect x="1" y="3" width="22" height="5" />
              <line x1="10" y1="12" x2="14" y2="12" />
            </svg>
            Archive Records
          </Link>
          {isAdmin && (
            <Link
              to="/admin/archive-settings"
              className="am-page-tab"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="14" height="14">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
              </svg>
              Archive Settings
            </Link>
          )}
        </div>

        {/* Summary stat cards */}
        <div className="am-stats-grid">
          <div className="am-stat-card">
            <div className="am-stat-icon blue">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polyline points="21 8 21 21 3 21 3 8" />
                <rect x="1" y="3" width="22" height="5" />
                <line x1="10" y1="12" x2="14" y2="12" />
              </svg>
            </div>
            <div className="am-stat-details">
              <span className="am-stat-label">Total Archived Records</span>
              <span className="am-stat-value">{totalArchived.toLocaleString()}</span>
            </div>
          </div>
          <div className="am-stat-card">
            <div className="am-stat-icon red">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
              </svg>
            </div>
            <div className="am-stat-details">
              <span className="am-stat-label">Archived Users</span>
              <span className="am-stat-value">{summary.users?.count ?? 0}</span>
            </div>
          </div>
          <div className="am-stat-card">
            <div className="am-stat-icon purple">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
              </svg>
            </div>
            <div className="am-stat-details">
              <span className="am-stat-label">Active Modules</span>
              <span className="am-stat-value">{Object.values(summary).filter(m => (m?.count ?? 0) > 0).length} / {MODULES.length}</span>
            </div>
          </div>
        </div>

        {/* Module tabs */}
        <div className="archive-tabs">
          {MODULES.map(m => (
            <button
              key={m.key}
              type="button"
              className={`archive-tab${activeModule === m.key ? ' archive-tab--active' : ''}`}
              onClick={() => switchModule(m.key)}
            >
              {m.label}
              {(summary[m.key]?.count ?? 0) > 0 && (
                <span className="archive-tab-count">{summary[m.key].count}</span>
              )}
            </button>
          ))}
        </div>

        {/* Filter bar */}
        <div className="archive-filter-bar">
          <input
            ref={searchRef}
            type="text"
            defaultValue={search}
            onChange={e => { setSearch(e.target.value); setPage(1); }}
            placeholder={`Search ${activeLabel}…`}
            className="archive-search-input"
          />
          <div className="archive-date-range">
            <label>Archived from:</label>
            <DateInput
              value={deletedFrom}
              onChange={e => { setDeletedFrom(e.target.value); setPage(1); }}
              className="archive-date-input"
            />
            <label>to:</label>
            <DateInput
              value={deletedTo}
              onChange={e => { setDeletedTo(e.target.value); setPage(1); }}
              className="archive-date-input"
            />
          </div>
          {(search || deletedFrom || deletedTo) && (
            <button type="button" className="archive-clear-filters" onClick={clearFilters}>
              Clear filters
            </button>
          )}
          <span className="archive-record-count">
            {totalItems} archived record{totalItems !== 1 ? 's' : ''}
          </span>
        </div>

        {/* Restore All */}
        {canRestore && totalItems > 0 && (
          <button type="button" className="archive-restore-all-btn" onClick={handleRestoreAll}>
            Restore All ({totalItems})
          </button>
        )}

        {/* Records table */}
        {loading ? (
          <Loader />
        ) : records.length === 0 ? (
          <div className="archive-empty">
            No archived {activeLabel} records found.
          </div>
        ) : (
          <table className="archive-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Name / Reference</th>
                <th>Code / ID</th>
                <th>Archived On</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {records.map((item, idx) => (
                <tr key={item.id}>
                  <td>{(page - 1) * PER_PAGE + idx + 1}</td>
                  <td>
                    <span className="archive-record-name">{item.display_name}</span>
                  </td>
                  <td className="archive-record-code">
                    {item.display_code ?? '—'}
                  </td>
                  <td className="archive-record-date">
                    {item.deleted_at
                      ? new Date(item.deleted_at).toLocaleString('en-US', {
                          month: '2-digit', day: '2-digit', year: 'numeric',
                          hour: '2-digit', minute: '2-digit',
                        })
                      : '—'}
                  </td>
                  <td>
                    <div className="archive-actions">
                      {canRestore && (
                        <button
                          type="button"
                          className="archive-restore-btn"
                          onClick={() => handleRestore(item.module, item.id)}
                        >
                          Restore
                        </button>
                      )}
                      {canForceDelete && item.module === 'users' && (
                        <button
                          type="button"
                          className="archive-release-btn"
                          onClick={() => handleReleaseIdentifiers(item.id, item.display_name)}
                          disabled={!!item.record?.identifiers_released_at}
                          title={item.record?.identifiers_released_at
                            ? 'Already released'
                            : 'Free this email and username for a new account, keeping the history'}
                        >
                          {item.record?.identifiers_released_at ? 'Released' : 'Release Email & Username'}
                        </button>
                      )}
                      {canForceDelete && (
                        <button
                          type="button"
                          className="archive-force-delete-btn"
                          onClick={() => handleForceDelete(item.module, item.id)}
                          title={item.module === 'users'
                            ? 'Only possible for an account that has never been used — anything '
                              + 'it is credited on would lose its owner'
                            : undefined}
                        >
                          Delete Permanently
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="archive-pagination">
            <button
              type="button"
              className="archive-page-btn"
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page === 1}
            >
              Previous
            </button>
            <span className="archive-page-info">
              Page {page} of {totalPages}
            </span>
            <button
              type="button"
              className="archive-page-btn"
              onClick={() => setPage(p => Math.min(totalPages, p + 1))}
              disabled={page === totalPages}
            >
              Next
            </button>
          </div>
        )}

      </div>
    </AdminLayout>
  );
};

export default ArchiveManagement;
