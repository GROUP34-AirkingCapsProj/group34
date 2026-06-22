import React, { useEffect, useMemo, useState, useCallback } from 'react';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import { auditAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/audit_trail.css';

const PAGE_SIZE = 15;
const AUDIT_LAST_SEEN_KEY = 'airking_audit_last_seen';

const TABLE_LABELS = {
  sales: 'Sale', sale_details: 'Sale Item',
  products: 'Product',
  purchase_orders: 'Purchase Order', purchase_order_details: 'PO Item',
  receivings: 'Receiving', receiving_details: 'Receiving Item',
  issuances: 'Issuance', issuance_details: 'Issuance Item',
  adjustments: 'Adjustment', adjustment_details: 'Adjustment Item',
  transfers: 'Transfer', transfer_details: 'Transfer Item',
  purchase_returns: 'Purchase Return', purchase_return_details: 'Purchase Return Item',
  delivery_receipts: 'Delivery Receipt',
  locations: 'Location', item_serials: 'Item Serial',
  users: 'User', inventory: 'Inventory',
};

const MODULE_MAP = {
  sales: 'SALES', sale_details: 'SALES',
  purchase_orders: 'PURCHASE', purchase_order_details: 'PURCHASE',
  purchase_returns: 'PURCHASE', purchase_return_details: 'PURCHASE',
  receivings: 'INVENTORY', receiving_details: 'INVENTORY',
  issuances: 'INVENTORY', issuance_details: 'INVENTORY',
  adjustments: 'INVENTORY', adjustment_details: 'INVENTORY',
  inventory: 'INVENTORY',
  transfers: 'TRANSFER', transfer_details: 'TRANSFER',
  delivery_receipts: 'DELIVERY',
  users: 'SYSTEM', locations: 'SYSTEM', item_serials: 'SYSTEM',
};

const MODULE_CLASS = {
  SALES: 'at-module-sales', INVENTORY: 'at-module-inventory',
  TRANSFER: 'at-module-transfer', PURCHASE: 'at-module-purchase',
  DELIVERY: 'at-module-delivery', SYSTEM: 'at-module-audit',
};

const ACTION_BADGE_CLASS = {
  create: 'at-action-badge--create',
  update: 'at-action-badge--update',
  delete: 'at-action-badge--delete',
};

const ACTION_LABELS = { create: 'Created', update: 'Updated', delete: 'Deleted' };
const SKIP_FIELDS = new Set(['created_at', 'updated_at', 'deleted_at']);

const DETAIL_TABLE_MAP = {
  purchase_order_details: 'purchase_orders',
  sale_details: 'sales',
  receiving_details: 'receivings',
  issuance_details: 'issuances',
  adjustment_details: 'adjustments',
  transfer_details: 'transfers',
  purchase_return_details: 'purchase_returns',
};

function formatVal(val) {
  if (val === null || val === undefined) return '—';
  if (typeof val === 'object') return JSON.stringify(val);
  const s = String(val);
  if (/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/.test(s)) { const [y, m, d] = s.slice(0, 10).split('-'); return `${m}/${d}/${y}`; }
  if (/^\d+(\.\d+)?$/.test(s) && Number(s) >= 1000) {
    return Number(s).toLocaleString('en-PH', { maximumFractionDigits: 2 });
  }
  return s.length > 40 ? s.slice(0, 38) + '…' : s;
}

function toLabel(key) {
  return key.replace(/_id$/, '').replace(/_/g, ' ');
}

function getDetailFields(newValues) {
  if (!newValues) return [];
  return Object.entries(newValues).filter(([k, v]) => {
    if (SKIP_FIELDS.has(k)) return false;
    if (v === null || v === '') return false;
    if (k.endsWith('_id') && typeof v === 'number') return false;
    return true;
  });
}

function groupAuditLogs(logs) {
  const groups = [];
  const used = new Set();
  const parentSet = new Set(Object.values(DETAIL_TABLE_MAP));

  for (let i = 0; i < logs.length; i++) {
    const log = logs[i];
    if (used.has(log.id)) continue;

    if (log.action !== 'create' || !parentSet.has(log.table)) {
      groups.push({ type: 'single', primary: log });
      used.add(log.id);
      continue;
    }

    const detailTable = Object.keys(DETAIL_TABLE_MAP).find(k => DETAIL_TABLE_MAP[k] === log.table);
    const details = [];

    for (let j = 0; j < logs.length; j++) {
      const d = logs[j];
      if (used.has(d.id) || d.id === log.id) continue;
      if (
        d.table === detailTable &&
        d.action === 'create' &&
        d.userId === log.userId &&
        Math.abs(d.createdAtMs - log.createdAtMs) <= 10000
      ) {
        details.push(d);
        used.add(d.id);
      }
    }

    used.add(log.id);
    groups.push(
      details.length > 0
        ? { type: 'grouped', primary: log, details }
        : { type: 'single', primary: log }
    );
  }
  return groups;
}

// Chevron icon
const Chevron = ({ open }) => (
  <svg
    className={`at-chevron${open ? ' at-chevron--open' : ''}`}
    viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"
    width="14" height="14" aria-hidden
  >
    <polyline points="9 18 15 12 9 6" />
  </svg>
);

// Collapsible detail body for a single entry
const EntryBody = ({ action, newValues, oldValues }) => {
  if (action === 'update' && newValues) {
    const entries = Object.entries(newValues).filter(([k]) => !SKIP_FIELDS.has(k));
    if (!entries.length) return null;
    return (
      <div className="at-change-list">
        {entries.map(([key, newVal]) => {
          const oldVal = oldValues?.[key];
          return (
            <div key={key} className="at-change-row">
              <span className="at-field-label">{toLabel(key)}</span>
              {oldVal !== undefined && <span className="at-old-val">{formatVal(oldVal)}</span>}
              <span className="at-change-arrow">→</span>
              <span className="at-new-val">{formatVal(newVal)}</span>
            </div>
          );
        })}
      </div>
    );
  }

  if (action === 'create' && newValues) {
    const entries = Object.entries(newValues)
      .filter(([k, v]) => !SKIP_FIELDS.has(k) && v !== null && v !== '')
      .slice(0, 6);
    if (!entries.length) return null;
    return (
      <div className="at-create-pills">
        {entries.map(([key, val]) => (
          <span key={key} className="at-create-pill">
            {toLabel(key)}: <strong>{formatVal(val)}</strong>
          </span>
        ))}
      </div>
    );
  }

  if (action === 'delete') {
    return (
      <div className="at-create-pills">
        <span className="at-create-pill at-create-pill--del">Record permanently removed</span>
      </div>
    );
  }

  return null;
};

// Collapsible detail body for a grouped (parent + line items) entry
const GroupedEntryBody = ({ primary, details }) => {
  const parentFields = Object.entries(primary.newValues || {})
    .filter(([k, v]) => !SKIP_FIELDS.has(k) && v !== null && v !== '')
    .slice(0, 6);

  return (
    <>
      {parentFields.length > 0 && (
        <div className="at-create-pills">
          {parentFields.map(([k, v]) => (
            <span key={k} className="at-create-pill">
              {toLabel(k)}: <strong>{formatVal(v)}</strong>
            </span>
          ))}
        </div>
      )}
      <div className="at-detail-items">
        <div className="at-item-count">
          {details.length} line item{details.length !== 1 ? 's' : ''}
        </div>
        {details.map((d, idx) => {
          const fields = getDetailFields(d.newValues);
          if (!fields.length) return null;
          return (
            <div key={d.id || idx} className="at-detail-item">
              {fields.map(([k, v]) => (
                <span key={k} className="at-create-pill at-create-pill--detail">
                  {toLabel(k)}: <strong>{formatVal(v)}</strong>
                </span>
              ))}
            </div>
          );
        })}
      </div>
    </>
  );
};

const AuditTrail = () => {
  const [searchTerm, setSearchTerm] = useState('');
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [lastSeenMs, setLastSeenMs] = useState(0);
  const [expandedIds, setExpandedIds] = useState(new Set());

  // Load last-seen timestamp; update it after 5 s so next visit shows no highlights
  useEffect(() => {
    const stored = localStorage.getItem(AUDIT_LAST_SEEN_KEY);
    setLastSeenMs(stored ? parseInt(stored, 10) : 0);
    const t = setTimeout(() => {
      localStorage.setItem(AUDIT_LAST_SEEN_KEY, Date.now().toString());
    }, 5000);
    return () => clearTimeout(t);
  }, []);

  useEffect(() => {
    let mounted = true;
    const load = async () => {
      setLoading(true);
      setError('');
      try {
        const res = await auditAPI.getTrail({ per_page: 200 });
        const payload = Array.isArray(res?.data?.data)
          ? res.data.data
          : Array.isArray(res?.data) ? res.data : [];
        if (mounted) setLogs(payload);
      } catch (e) {
        if (!mounted) return;
        const msg = e.message || 'Failed to load audit logs.';
        setError(msg);
        toast.error(msg);
      } finally {
        if (mounted) setLoading(false);
      }
    };
    load();
    return () => { mounted = false; };
  }, []);

  const normalizedLogs = useMemo(() => logs.map((log, index) => {
    const operatorName =
      `${log?.user?.first_name || ''} ${log?.user?.last_name || ''}`.trim() ||
      log?.user?.username || 'System';
    const table = log?.table_name || '';
    return {
      id: log?.audit_id ?? log?.id ?? index,
      userId: log?.user_id ?? null,
      createdAtMs: log?.created_at ? new Date(log.created_at).getTime() : 0,
      timestamp: log?.created_at ? new Date(log.created_at).toLocaleString() : 'N/A',
      operator: operatorName,
      operatorRole: log?.user?.role?.role_name || 'N/A',
      module: MODULE_MAP[table] || 'SYSTEM',
      moduleLabel: TABLE_LABELS[table] || table.replace(/_/g, ' ') || 'System',
      action: log?.action || 'N/A',
      recordId: log?.record_id,
      ip: log?.ip_address || 'N/A',
      newValues: log?.new_values || null,
      oldValues: log?.old_values || null,
      table,
    };
  }), [logs]);

  const filteredLogs = useMemo(() => {
    setCurrentPage(1);
    if (!searchTerm) return normalizedLogs;
    const q = searchTerm.toLowerCase();
    return normalizedLogs.filter(log =>
      log.operator.toLowerCase().includes(q) ||
      log.action.toLowerCase().includes(q) ||
      log.module.toLowerCase().includes(q) ||
      log.moduleLabel.toLowerCase().includes(q) ||
      log.table.toLowerCase().includes(q) ||
      log.ip.toLowerCase().includes(q) ||
      String(log.recordId ?? '').includes(q)
    );
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [normalizedLogs, searchTerm]);

  const groupedLogs = useMemo(() => groupAuditLogs(filteredLogs), [filteredLogs]);

  // Auto-expand unread groups once both groups and lastSeenMs are ready
  useEffect(() => {
    if (!groupedLogs.length || lastSeenMs === 0) return;
    setExpandedIds(new Set(
      groupedLogs
        .filter(g => g.primary.createdAtMs > lastSeenMs)
        .map(g => g.primary.id)
    ));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupedLogs.length, lastSeenMs]);

  const toggleExpanded = useCallback((id) => {
    setExpandedIds(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }, []);

  const isUnread = (createdAtMs) => lastSeenMs > 0 && createdAtMs > lastSeenMs;
  const unreadCount = groupedLogs.filter(g => isUnread(g.primary.createdAtMs)).length;

  const totalPages = Math.ceil(groupedLogs.length / PAGE_SIZE) || 1;
  const pagedGroups = groupedLogs.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  return (
    <AdminLayout>
      <div className="ak-page at-page">
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Governance · Audit</p>
              <h1 className="ak-title">Audit Trail</h1>
              <p className="ak-sub">Comprehensive historical record of all system interactions.</p>
            </div>
          </div>
        </header>

        <div className="at-search-row">
          <div className="at-search-input">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16">
              <circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" />
            </svg>
            <input
              type="text"
              placeholder="Search by user, action, module, record ID, or IP..."
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
            />
          </div>
        </div>

        <div className="at-table-card">
          <div className="at-table-top">
            <h3>
              Activity Logs ({groupedLogs.length})
              {unreadCount > 0 && (
                <span className="at-unread-badge">{unreadCount} new</span>
              )}
            </h3>
            <p className="at-table-hint">Click any row to expand or collapse details</p>
          </div>

          <div className="at-table-wrapper">
            <table className="at-table">
              <thead>
                <tr>
                  <th>TIMESTAMP</th>
                  <th>OPERATOR</th>
                  <th>ACTION / DETAILS</th>
                  <th>IP ADDRESS</th>
                  <th>VERIFIED</th>
                </tr>
              </thead>
              <tbody>
                {loading && (
                  <tr><td colSpan="5" className="at-cell-center">Loading audit logs...</td></tr>
                )}
                {!loading && error && (
                  <tr><td colSpan="5" className="at-cell-center at-cell-error">{error}</td></tr>
                )}
                {!loading && !error && groupedLogs.length === 0 && (
                  <tr><td colSpan="5" className="at-cell-center">No audit records found.</td></tr>
                )}

                {pagedGroups.map((group) => {
                  const log = group.primary;
                  const expanded = expandedIds.has(log.id);
                  const unread = isUnread(log.createdAtMs);

                  return (
                    <tr
                      key={log.id}
                      className={unread ? 'at-row--unread' : ''}
                    >
                      <td className="at-cell-timestamp">
                        {unread && <span className="at-unread-dot" aria-label="Unread" />}
                        {log.timestamp}
                      </td>
                      <td>
                        <div className="at-operator-cell">
                          <span className="at-operator-name">{log.operator}</span>
                          <span className="at-operator-role">{log.operatorRole}</span>
                        </div>
                      </td>
                      <td>
                        <div className="at-action-cell">
                          {/* Clickable header row */}
                          <div
                            className="at-entry-header"
                            onClick={() => toggleExpanded(log.id)}
                            role="button"
                            tabIndex={0}
                            onKeyDown={e => e.key === 'Enter' && toggleExpanded(log.id)}
                            aria-expanded={expanded}
                          >
                            <div className="at-entry-header-left">
                              <span className={`at-action-badge ${ACTION_BADGE_CLASS[log.action] || ''}`}>
                                {ACTION_LABELS[log.action] || log.action}
                              </span>
                              <span className={`at-module-badge ${MODULE_CLASS[log.module] || ''}`}>
                                {log.module}
                              </span>
                              <span className="at-record-label">
                                {log.moduleLabel}{log.recordId != null ? ` #${log.recordId}` : ''}
                              </span>
                              {group.type === 'grouped' && (
                                <span className="at-item-count-inline">
                                  · {group.details.length} item{group.details.length !== 1 ? 's' : ''}
                                </span>
                              )}
                            </div>
                            <Chevron open={expanded} />
                          </div>

                          {/* Collapsible body */}
                          {expanded && (
                            <div className="at-entry-body">
                              {group.type === 'grouped' ? (
                                <GroupedEntryBody primary={log} details={group.details} />
                              ) : (
                                <EntryBody
                                  action={log.action}
                                  newValues={log.newValues}
                                  oldValues={log.oldValues}
                                />
                              )}
                            </div>
                          )}
                        </div>
                      </td>
                      <td className="at-cell-ip">{log.ip}</td>
                      <td>
                        <div className="at-verified-icon">
                          <svg viewBox="0 0 24 24" fill="none" stroke="#059669" strokeWidth="2.5" width="20" height="20">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                            <polyline points="22 4 12 14.01 9 11.01" />
                          </svg>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={setCurrentPage}
            totalItems={groupedLogs.length}
            pageSize={PAGE_SIZE}
            label="log entries"
          />
        </div>
      </div>
    </AdminLayout>
  );
};

export default AuditTrail;
