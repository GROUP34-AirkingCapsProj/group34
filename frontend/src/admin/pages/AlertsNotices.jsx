import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import Pagination from '../components/Pagination';
import { useAuth } from '../context/AuthContext';
import { auditAPI, dashboardAPI, alertsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/alerts_notices.css';
import DateInput from '../components/DateInput';
import { pendingPoTarget, pendingPrTarget, lowStockTarget, auditTarget, targetHref } from '../utils/alertTarget';

const PAGE_SIZE = 10;
const AUDIT_LAST_SEEN_KEY = 'airking_audit_last_seen';

const TABLE_LABELS = {
  sales: 'Sale', sale_details: 'Sale Item',
  products: 'Product',
  purchase_orders: 'Purchase Order', purchase_order_details: 'PO Item',
  purchase_requests: 'Purchase Request', purchase_request_details: 'PR Line Item',
  receivings: 'Receiving Session', receiving_details: 'Receiving Item',
  issuances: 'Issuance', issuance_details: 'Issuance Item',
  adjustments: 'Adjustment', adjustment_details: 'Adjustment Item',
  transfers: 'Transfer', transfer_details: 'Transfer Item',
  purchase_returns: 'Purchase Return', purchase_return_details: 'Purchase Return Item',
  delivery_receipts: 'Delivery Receipt',
  locations: 'Location', item_serials: 'Item Serial',
  users: 'User', inventory: 'Inventory',
  scan_corrections: 'Barcode Correction',
  warranty_records: 'Warranty Record',
  budget_allocations: 'Budget Allocation',
  customers: 'Customer',
};

const SKIP_FIELDS = new Set(['created_at', 'updated_at', 'deleted_at']);

const ACTION_LABELS = {
  create:               'Created',
  update:               'Updated',
  delete:               'Deleted',
  login:                'Login',
  logout:               'Logout',
  register:             'Registered',
  barcode_correction:   'Barcode Correction',
  receiving:            'Stock Received',
  issuance_completed:   'Issuance Completed',
  inventory_adjustment: 'Inventory Adjusted',
  stock_transfer:       'Stock Transferred',
};

const BADGE_VARIANT = {
  create:               'create',
  update:               'update',
  delete:               'delete',
  login:                'info',
  logout:               'info',
  register:             'info',
  barcode_correction:   'warning',
  receiving:            'create',
  issuance_completed:   'create',
  inventory_adjustment: 'warning',
  stock_transfer:       'update',
};

const badgeVariant = (action) => BADGE_VARIANT[action] || 'info';

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
  return s.length > 60 ? s.slice(0, 57) + '…' : s;
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

function groupRawAuditLogs(logs) {
  const groups = [];
  const used = new Set();
  const parentSet = new Set(Object.values(DETAIL_TABLE_MAP));

  for (let i = 0; i < logs.length; i++) {
    const log = logs[i];
    const logId = log?.audit_id ?? log?.id ?? i;
    if (used.has(logId)) continue;

    if (log.action !== 'create' || !parentSet.has(log.table_name)) {
      groups.push({ type: 'single', primary: log });
      used.add(logId);
      continue;
    }

    const detailTable = Object.keys(DETAIL_TABLE_MAP).find(k => DETAIL_TABLE_MAP[k] === log.table_name);
    const parentTime = log.created_at ? new Date(log.created_at).getTime() : 0;
    const details = [];

    for (let j = 0; j < logs.length; j++) {
      const d = logs[j];
      const dId = d?.audit_id ?? d?.id ?? j;
      if (used.has(dId) || dId === logId) continue;
      const dTime = d.created_at ? new Date(d.created_at).getTime() : 0;
      if (
        d.table_name === detailTable &&
        d.action === 'create' &&
        d.user_id === log.user_id &&
        Math.abs(dTime - parentTime) <= 10000
      ) {
        details.push(d);
        used.add(dId);
      }
    }

    used.add(logId);
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
    className={`an-chevron${open ? ' an-chevron--open' : ''}`}
    viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"
    width="14" height="14" aria-hidden
  >
    <polyline points="9 18 15 12 9 6" />
  </svg>
);

// Single audit entry (UPDATE / DELETE / ungrouped CREATE)
const SingleAuditEntry = ({ item, isUnread, isExpanded, onToggle, onOpen }) => {
  const id = item?.audit_id ?? item?.id;
  const userName =
    item?.user_full_name ||
    `${item?.user?.first_name || ''} ${item?.user?.last_name || ''}`.trim() ||
    item?.user?.username || 'System';
  const action = item?.action || 'activity';
  const table = item?.table_name || '';
  const moduleLabel = item?.module_label || TABLE_LABELS[table] || (table ? table.replace(/_/g, ' ') : 'System');
  const actionLabel = item?.action_label || ACTION_LABELS[action] || action.replace(/_/g, ' ');
  const newVals = item?.new_values;
  const oldVals = item?.old_values;

  const changedFields =
    action === 'update' && newVals
      ? Object.entries(newVals).filter(([k]) => !SKIP_FIELDS.has(k))
      : [];

  const createFields =
    action === 'create' && newVals
      ? Object.entries(newVals)
          .filter(([k, v]) => !SKIP_FIELDS.has(k) && v !== null && v !== '')
          .slice(0, 6)
      : [];

  const hasBody = changedFields.length > 0 || createFields.length > 0 || action === 'delete';

  return (
    <div className={`an-approval-item an-audit-item${isUnread ? ' an-audit-item--unread' : ''}`}>
      <div className="an-audit-left">
        {/* Clickable header */}
        <div
          className="an-entry-header"
          onClick={() => hasBody && onToggle(id)}
          role={hasBody ? 'button' : undefined}
          tabIndex={hasBody ? 0 : undefined}
          onKeyDown={e => hasBody && e.key === 'Enter' && onToggle(id)}
          style={{ cursor: hasBody ? 'pointer' : 'default' }}
          aria-expanded={isExpanded}
        >
          <div className="an-entry-header-left">
            <span className={`an-audit-badge an-audit-badge--${badgeVariant(action)}`}>
              {actionLabel}
            </span>
            <span className="an-audit-module">
              {moduleLabel}{item?.record_id != null ? ` #${item.record_id}` : ''}
            </span>
            {isUnread && <span className="an-unread-dot" aria-label="Unread" />}
          </div>
          {hasBody && <Chevron open={isExpanded} />}
        </div>

        {/* Meta row — always visible */}
        <div className="an-audit-meta">
          <span className="an-audit-user">{userName}</span>
          <span className="an-audit-sep">·</span>
          <span className="an-audit-time">
            {item?.created_at ? new Date(item.created_at).toLocaleString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''}
          </span>
          {onOpen && (
            <>
              <span className="an-audit-sep">·</span>
              <button
                type="button"
                className="an-audit-open-link"
                onClick={(e) => { e.stopPropagation(); onOpen(); }}
              >
                Open record <span aria-hidden="true">→</span>
              </button>
            </>
          )}
          {item?.ip_address && (
            <><span className="an-audit-sep">·</span><span className="an-audit-ip">{item.ip_address}</span></>
          )}
        </div>

        {/* Pre-computed change summary from v2 endpoint */}
        {item?.change_summary && (
          <div className="an-audit-summary">{item.change_summary}</div>
        )}

        {/* Collapsible body */}
        {isExpanded && (
          <div className="an-entry-body">
            {changedFields.length > 0 && (
              <div className="an-audit-changes">
                {changedFields.map(([key, newVal]) => {
                  const oldVal = oldVals?.[key];
                  return (
                    <div key={key} className="an-audit-change-row">
                      <span className="an-audit-field-name">{toLabel(key)}</span>
                      {oldVal !== undefined && <span className="an-audit-old-val">{formatVal(oldVal)}</span>}
                      <span className="an-audit-arrow">→</span>
                      <span className="an-audit-new-val">{formatVal(newVal)}</span>
                    </div>
                  );
                })}
              </div>
            )}
            {createFields.length > 0 && (
              <div className="an-audit-create-fields">
                {createFields.map(([key, val]) => (
                  <span key={key} className="an-audit-field-pill">
                    {toLabel(key)}: <strong>{formatVal(val)}</strong>
                  </span>
                ))}
              </div>
            )}
            {action === 'delete' && (
              <div className="an-audit-create-fields">
                <span className="an-audit-field-pill an-audit-field-pill--del">Record permanently removed</span>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

// Grouped entry: parent header + all line items in one collapsible card
const GroupedAuditEntry = ({ primary, details, isUnread, isExpanded, onToggle, onOpen }) => {
  const id = primary?.audit_id ?? primary?.id;
  const userName =
    primary?.user_full_name ||
    `${primary?.user?.first_name || ''} ${primary?.user?.last_name || ''}`.trim() ||
    primary?.user?.username || 'System';
  const table = primary?.table_name || 'system';
  const moduleLabel = TABLE_LABELS[table] || table.replace(/_/g, ' ');
  const parentFields = Object.entries(primary?.new_values || {})
    .filter(([k, v]) => !SKIP_FIELDS.has(k) && v !== null && v !== '')
    .slice(0, 6);

  return (
    <div className={`an-approval-item an-audit-item${isUnread ? ' an-audit-item--unread' : ''}`}>
      <div className="an-audit-left">
        {/* Clickable header */}
        <div
          className="an-entry-header"
          onClick={() => onToggle(id)}
          role="button"
          tabIndex={0}
          onKeyDown={e => e.key === 'Enter' && onToggle(id)}
          aria-expanded={isExpanded}
        >
          <div className="an-entry-header-left">
            <span className="an-audit-badge an-audit-badge--create">Created</span>
            <span className="an-audit-module">
              {moduleLabel}{primary?.record_id != null ? ` #${primary.record_id}` : ''}
            </span>
            <span className="an-item-count-inline">
              · {details.length} item{details.length !== 1 ? 's' : ''}
            </span>
            {isUnread && <span className="an-unread-dot" aria-label="Unread" />}
          </div>
          <Chevron open={isExpanded} />
        </div>

        {/* Meta row — always visible */}
        <div className="an-audit-meta">
          <span className="an-audit-user">{userName}</span>
          <span className="an-audit-sep">·</span>
          <span className="an-audit-time">
            {primary?.created_at ? new Date(primary.created_at).toLocaleString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''}
          </span>
          {onOpen && (
            <>
              <span className="an-audit-sep">·</span>
              <button
                type="button"
                className="an-audit-open-link"
                onClick={(e) => { e.stopPropagation(); onOpen(); }}
              >
                Open record <span aria-hidden="true">→</span>
              </button>
            </>
          )}
          {primary?.ip_address && (
            <><span className="an-audit-sep">·</span><span className="an-audit-ip">{primary.ip_address}</span></>
          )}
        </div>

        {/* Collapsible body */}
        {isExpanded && (
          <div className="an-entry-body">
            {parentFields.length > 0 && (
              <div className="an-audit-create-fields">
                {parentFields.map(([k, v]) => (
                  <span key={k} className="an-audit-field-pill">
                    {toLabel(k)}: <strong>{formatVal(v)}</strong>
                  </span>
                ))}
              </div>
            )}
            <div className="an-detail-items">
              <div className="an-detail-item-count">
                {details.length} line item{details.length !== 1 ? 's' : ''}
              </div>
              {details.map((d, idx) => {
                const fields = getDetailFields(d.new_values);
                if (!fields.length) return null;
                return (
                  <div key={d?.audit_id ?? d?.id ?? idx} className="an-detail-item">
                    {fields.map(([k, v]) => (
                      <span key={k} className="an-audit-field-pill an-audit-field-pill--detail">
                        {toLabel(k)}: <strong>{formatVal(v)}</strong>
                      </span>
                    ))}
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

const AlertsNotices = () => {
  const navigate = useNavigate();

  // Turns a non-null alert target into spreadable props that navigate on
  // click/Enter/Space. Returns {} when the item is not navigable.
  const clickProps = useCallback((target) => {
    if (!target) return {};
    const go = () => navigate(targetHref(target));
    return {
      role: 'button',
      tabIndex: 0,
      onClick: go,
      onKeyDown: (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
      },
    };
  }, [navigate]);

  const { can } = useAuth();
  // Audit trail is a separate privileged compliance feature (own /admin/audit page).
  // Only admin/branch_manager/auditor hold audit_trail_viewing.read; IA & WP must still
  // see their location-scoped alerts without that section breaking the page.
  const canViewAudit = can('audit_trail_viewing', 'read');
  const [allLowStock, setAllLowStock] = useState([]);
  const [allAudit, setAllAudit] = useState([]);
  const [pendingPOs, setPendingPOs] = useState([]);
  const [pendingPRs, setPendingPRs] = useState([]);
  const [pendingProducts, setPendingProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [markingRead, setMarkingRead] = useState(false);
  const [stockPage, setStockPage] = useState(1);
  const [auditPage, setAuditPage] = useState(1);
  const [lastSeenMs, setLastSeenMs] = useState(0);
  const [expandedIds, setExpandedIds] = useState(new Set());
  const [auditActionFilter, setAuditActionFilter] = useState('');
  const [auditDateFrom, setAuditDateFrom] = useState('');
  const [auditDateTo, setAuditDateTo] = useState('');

  // Load last-seen timestamp; update it after 5 s so next visit clears highlights
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
    const loadAlerts = async () => {
      setLoading(true);
      // Settle each load independently so a single failure (e.g. a role without
      // audit_trail_viewing 403ing on /audit-trail/v2) never blanks out the
      // alerts a user IS allowed to see. Audit is only requested when permitted.
      const [lowStockR, pendingR, auditR] = await Promise.allSettled([
        dashboardAPI.getLowStock(),
        dashboardAPI.getPendingApprovals(),
        canViewAudit ? auditAPI.getTrailV2({ per_page: 100 }) : Promise.resolve(null),
      ]);
      if (!mounted) return;

      let coreFailed = false;

      // getLowStock returns { success, data: [...] }
      if (lowStockR.status === 'fulfilled') {
        setAllLowStock(Array.isArray(lowStockR.value?.data) ? lowStockR.value.data : []);
      } else {
        coreFailed = true;
      }

      // pendingApprovals returns { success, data: { pending_pos, pending_prs, pending_products, ... } }
      if (pendingR.status === 'fulfilled') {
        const pendingData = pendingR.value?.data?.data ?? pendingR.value?.data ?? {};
        setPendingPOs(Array.isArray(pendingData.pending_pos) ? pendingData.pending_pos : []);
        setPendingPRs(Array.isArray(pendingData.pending_prs) ? pendingData.pending_prs : []);
        setPendingProducts(Array.isArray(pendingData.pending_products) ? pendingData.pending_products : []);
      } else {
        coreFailed = true;
      }

      // v2 returns { success, data: [...], pagination } — data is the items array directly.
      // Audit failure is non-fatal: the section is simply hidden/empty.
      if (canViewAudit && auditR.status === 'fulfilled' && auditR.value) {
        const auditRes = auditR.value;
        setAllAudit(Array.isArray(auditRes?.data)
          ? auditRes.data
          : Array.isArray(auditRes?.data?.data) ? auditRes.data.data : []);
      }

      if (coreFailed) toast.error('Some alerts failed to load.');
      setLoading(false);
    };
    loadAlerts();
    return () => { mounted = false; };
    // Re-run once permissions resolve so audit is fetched for roles that gain the grant.
  }, [canViewAudit]);

  const groupedAudit = useMemo(() => groupRawAuditLogs(allAudit), [allAudit]);

  const filteredAudit = useMemo(() => {
    return groupedAudit.filter(g => {
      const p = g.primary;
      if (auditActionFilter && p?.action !== auditActionFilter) return false;
      if (auditDateFrom && p?.created_at < auditDateFrom) return false;
      if (auditDateTo && p?.created_at > auditDateTo + 'T23:59:59') return false;
      return true;
    });
  }, [groupedAudit, auditActionFilter, auditDateFrom, auditDateTo]);

  const isUnread = useCallback(
    (createdAt) => lastSeenMs > 0 && new Date(createdAt).getTime() > lastSeenMs,
    [lastSeenMs]
  );

  // Auto-expand unread groups when both groups and lastSeenMs are ready
  useEffect(() => {
    if (!groupedAudit.length || lastSeenMs === 0) return;
    setExpandedIds(new Set(
      groupedAudit
        .filter(g => isUnread(g.primary?.created_at))
        .map(g => g.primary?.audit_id ?? g.primary?.id)
    ));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupedAudit.length, lastSeenMs]);

  const toggleExpanded = useCallback((id) => {
    setExpandedIds(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }, []);

  const unreadCount = groupedAudit.filter(g => isUnread(g.primary?.created_at)).length;

  const handleMarkAllRead = async () => {
    if (markingRead) return;
    setMarkingRead(true);
    try {
      await alertsAPI.markAllRead();
      // Also mark audit trail as read
      const now = Date.now();
      localStorage.setItem(AUDIT_LAST_SEEN_KEY, now.toString());
      setLastSeenMs(now);
      setExpandedIds(new Set());
      toast.success('All alerts marked as read');
      const pendingRes = await dashboardAPI.getPendingApprovals();
      const pendingData = pendingRes?.data?.data ?? pendingRes?.data ?? {};
      setPendingPOs(Array.isArray(pendingData.pending_pos) ? pendingData.pending_pos : []);
      setPendingPRs(Array.isArray(pendingData.pending_prs) ? pendingData.pending_prs : []);
      setPendingProducts(Array.isArray(pendingData.pending_products) ? pendingData.pending_products : []);
    } catch (e) {
      toast.error(e.message || 'Failed to mark alerts as read');
    } finally {
      setMarkingRead(false);
    }
  };

  const stockTotal = allLowStock.length;
  const stockTotalPages = Math.ceil(stockTotal / PAGE_SIZE) || 1;
  const stockVisible = allLowStock.slice((stockPage - 1) * PAGE_SIZE, stockPage * PAGE_SIZE);

  const auditTotal = filteredAudit.length;
  const auditTotalPages = Math.ceil(auditTotal / PAGE_SIZE) || 1;
  const auditVisible = filteredAudit.slice((auditPage - 1) * PAGE_SIZE, auditPage * PAGE_SIZE);

  const pendingTotal = pendingPOs.length + pendingPRs.length + pendingProducts.length;

  return (
    <AdminLayout>
      <div className="ak-page an-page">
        <header className="ak-header">
          <div className="ak-header-row">
          <div className="an-page-header-content">
            <p className="ak-eyebrow">System · Alerts</p>
            <h1 className="ak-title">Alerts &amp; Notices</h1>
            <p className="ak-sub">
              System alerts and activity — all actions and changes are recorded here.
            </p>
          </div>
          <button
            type="button"
            className="ak-btn ak-btn--ghost an-mark-all-read-btn"
            onClick={handleMarkAllRead}
            disabled={markingRead || (pendingTotal + unreadCount) === 0}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16" aria-hidden>
              <polyline points="20 6 9 17 4 12" />
            </svg>
            {markingRead ? 'Marking...' : 'Mark All Read'}
          </button>
          </div>
        </header>

        {/* Pending approvals */}
        <div className="an-card">
          <div className="an-card-header--row">
            <div>
              <h3 className="an-card-title">
                Pending approvals
                {!loading && pendingTotal > 0 && (
                  <span className="an-pending-badge">{pendingTotal}</span>
                )}
              </h3>
              <p className="an-card-subtitle">Items waiting for your approval</p>
            </div>
            <button type="button" className="an-go-queue-btn" onClick={() => navigate('/admin/draft-po-creator')}>
              Open approval queue
            </button>
          </div>

          {!loading && pendingTotal > 0 && (
            <div className="an-alert-banner">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18" aria-hidden>
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
              </svg>
              <span>
                <strong>{pendingTotal} item{pendingTotal !== 1 ? 's' : ''}</strong> require your approval.
                Review and act on them as soon as possible.
              </span>
            </div>
          )}

          <div className="an-approval-list">
            {loading ? (
              <Loader label="Loading pending approvals" />
            ) : pendingTotal === 0 ? (
              <div className="an-empty">No pending approvals at this time</div>
            ) : (
              <>
                {pendingPOs.length > 0 && (
                  <>
                    <div className="an-approval-group-label">
                      Purchase Orders <span className="an-approval-group-count">{pendingPOs.length}</span>
                    </div>
                    {pendingPOs.map((po) => (
                      <div
                        className={`an-approval-item an-approval-item--urgent${pendingPoTarget(po) ? ' an-clickable' : ''}`}
                        key={`po-${po.id}`}
                        {...clickProps(pendingPoTarget(po))}
                      >
                        <div className="an-approval-left">
                          <span className="an-approval-title">
                            {po.po_number || `PO #${po.id}`}
                            {po.supplier && <span className="an-approval-supplier"> &mdash; {po.supplier}</span>}
                          </span>
                          <span className="an-approval-detail">
                            {po.order_date && <>Order date: {po.order_date} &bull; </>}
                            {po.total_amount > 0 && <>Amount: &#8369;{Number(po.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })} &bull; </>}
                            Submitted by: {po.created_by}
                          </span>
                        </div>
                        <div className="an-approval-right">
                          <span className="an-approval-status">Awaiting approval</span>
                        </div>
                      </div>
                    ))}
                  </>
                )}
                {pendingPRs.length > 0 && (
                  <>
                    <div className="an-approval-group-label">
                      Purchase Requests <span className="an-approval-group-count">{pendingPRs.length}</span>
                    </div>
                    {pendingPRs.map((pr) => (
                      <div
                        className={`an-approval-item an-approval-item--urgent${pendingPrTarget(pr) ? ' an-clickable' : ''}`}
                        key={`pr-${pr.id}`}
                        {...clickProps(pendingPrTarget(pr))}
                      >
                        <div className="an-approval-left">
                          <span className="an-approval-title">
                            {pr.pr_number || `PR #${pr.id}`}
                            {pr.location_name && <span className="an-approval-supplier"> &mdash; {pr.location_name}</span>}
                          </span>
                          <span className="an-approval-detail">
                            {pr.request_date && <>Request date: {pr.request_date} &bull; </>}
                            {pr.estimated_total > 0 && <>Amount: &#8369;{Number(pr.estimated_total).toLocaleString('en-PH', { minimumFractionDigits: 2 })} &bull; </>}
                            Requested by: {pr.requested_by}
                          </span>
                        </div>
                        <div className="an-approval-right">
                          <span className="an-approval-status">Awaiting approval</span>
                        </div>
                      </div>
                    ))}
                  </>
                )}
                {pendingProducts.length > 0 && (
                  <>
                    <div className="an-approval-group-label">
                      Item Registrations <span className="an-approval-group-count">{pendingProducts.length}</span>
                    </div>
                    {pendingProducts.map((p) => (
                      <div className="an-approval-item an-approval-item--urgent" key={`prod-${p.id}`}>
                        <div className="an-approval-left">
                          <span className="an-approval-title">{p.generated_name || `Registration #${p.id}`}</span>
                          <span className="an-approval-detail">
                            {p.registration_kind && <>Type: {p.registration_kind}</>}
                          </span>
                        </div>
                        <div className="an-approval-right">
                          <span className="an-approval-status">Awaiting approval</span>
                        </div>
                      </div>
                    ))}
                  </>
                )}
              </>
            )}
          </div>
        </div>

        {/* Low stock alerts */}
        <div className="an-card">
          <div className="an-card-header">
            <h3 className="an-card-title">Low stock alerts</h3>
            <p className="an-card-subtitle">Products at or below reorder level</p>
          </div>
          <div className="an-approval-list">
            {loading ? (
              <Loader label="Loading alerts" />
            ) : allLowStock.length === 0 ? (
              <div className="an-empty">No low-stock alerts at the moment</div>
            ) : (
              stockVisible.map((item, idx) => (
                <div
                  className={`an-approval-item${lowStockTarget(item) ? ' an-clickable' : ''}`}
                  key={item.product_id || item.inventory_id || idx}
                  {...clickProps(lowStockTarget(item))}
                >
                  <div className="an-approval-left">
                    <span className="an-approval-title">
                      {item.product_name || `Item ${idx + 1}`}
                      {item.brand && (
                        <span className="an-approval-supplier"> &mdash; {item.brand}</span>
                      )}
                    </span>
                    <span className="an-approval-detail">
                      {item.category && <>{item.category} &bull; </>}
                      On hand: {Number(item.quantity ?? item.quantity_on_hand ?? 0).toLocaleString('en-PH')}
                      {' '}&bull; Reorder level: {Number(item.recommended_stocks ?? item.safety_stock ?? 0).toLocaleString('en-PH')}
                      {(item.deficiency ?? item.shortage) > 0 && (
                        <> &bull; <strong>Short by {Number(item.deficiency ?? item.shortage).toLocaleString('en-PH')}</strong></>
                      )}
                    </span>
                  </div>
                  <div className="an-approval-right">
                    <span className="an-approval-status">Needs reorder</span>
                  </div>
                </div>
              ))
            )}
          </div>
          {!loading && (
            <Pagination currentPage={stockPage} totalPages={stockTotalPages} onPageChange={setStockPage}
              totalItems={stockTotal} pageSize={PAGE_SIZE} label="low-stock items" />
          )}
        </div>

        {/* Audit trail activity — privileged compliance view (audit_trail_viewing.read) */}
        {canViewAudit && (
        <div className="an-card">
          <div className="an-card-header">
            <h3 className="an-card-title">
              Audit Trail — System Activity
              {unreadCount > 0 && <span className="an-unread-badge">{unreadCount} new</span>}
            </h3>
            <p className="an-card-subtitle">Click any entry to expand or collapse details</p>
          </div>

          {/* Filter bar */}
          <div className="an-audit-filters">
            <select
              value={auditActionFilter}
              onChange={e => { setAuditActionFilter(e.target.value); setAuditPage(1); }}
              className="an-filter-select"
            >
              <option value="">All actions</option>
              {Object.entries(ACTION_LABELS).map(([val, label]) => (
                <option key={val} value={val}>{label}</option>
              ))}
            </select>
            <DateInput
              value={auditDateFrom}
              onChange={e => { setAuditDateFrom(e.target.value); setAuditPage(1); }}
              className="an-filter-date"
              aria-label="Filter from date"
            />
            <DateInput
              value={auditDateTo}
              onChange={e => { setAuditDateTo(e.target.value); setAuditPage(1); }}
              className="an-filter-date"
              aria-label="Filter to date"
            />
            {(auditActionFilter || auditDateFrom || auditDateTo) && (
              <button
                type="button"
                className="an-filter-clear"
                onClick={() => { setAuditActionFilter(''); setAuditDateFrom(''); setAuditDateTo(''); setAuditPage(1); }}
              >
                Clear
              </button>
            )}
            <span className="an-filter-count">
              {filteredAudit.length} {filteredAudit.length === 1 ? 'entry' : 'entries'}
            </span>
          </div>

          <div className="an-approval-list">
            {loading ? (
              <Loader />
            ) : groupedAudit.length === 0 ? (
              <div className="an-empty">No audit entries yet</div>
            ) : filteredAudit.length === 0 ? (
              <div className="an-empty">No entries match the selected filters</div>
            ) : (
              auditVisible.map((group, idx) => {
                const gId = group.primary?.audit_id ?? group.primary?.id ?? idx;
                const expanded = expandedIds.has(gId);
                const unread = isUnread(group.primary?.created_at);
                const target = auditTarget(group.primary);
                const onOpen = target ? () => navigate(targetHref(target)) : null;
                return group.type === 'grouped' ? (
                  <GroupedAuditEntry
                    key={gId}
                    primary={group.primary}
                    details={group.details}
                    isUnread={unread}
                    isExpanded={expanded}
                    onToggle={toggleExpanded}
                    onOpen={onOpen}
                  />
                ) : (
                  <SingleAuditEntry
                    key={gId}
                    item={group.primary}
                    isUnread={unread}
                    isExpanded={expanded}
                    onToggle={toggleExpanded}
                    onOpen={onOpen}
                  />
                );
              })
            )}
          </div>
          {!loading && (
            <Pagination currentPage={auditPage} totalPages={auditTotalPages} onPageChange={setAuditPage}
              totalItems={auditTotal} pageSize={PAGE_SIZE} label="audit entries" />
          )}
        </div>
        )}
      </div>
    </AdminLayout>
  );
};

export default AlertsNotices;
