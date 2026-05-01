import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import { auditAPI, dashboardAPI, alertsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/dashboard_air.css';
import '../styles/alerts_notices.css';

const PAGE_SIZE = 10;
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

const SKIP_FIELDS = new Set(['created_at', 'updated_at', 'deleted_at']);
const ACTION_LABELS = { create: 'Created', update: 'Updated', delete: 'Deleted' };

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
  if (/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/.test(s)) return s.slice(0, 10);
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
const SingleAuditEntry = ({ item, isUnread, isExpanded, onToggle }) => {
  const id = item?.audit_id ?? item?.id;
  const userName =
    `${item?.user?.first_name || ''} ${item?.user?.last_name || ''}`.trim() ||
    item?.user?.username || 'System';
  const action = item?.action || 'activity';
  const table = item?.table_name || 'system';
  const moduleLabel = TABLE_LABELS[table] || table.replace(/_/g, ' ');
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
            <span className={`an-audit-badge an-audit-badge--${action}`}>
              {ACTION_LABELS[action] || action}
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
            {item?.created_at ? new Date(item.created_at).toLocaleString() : ''}
          </span>
          {item?.ip_address && (
            <><span className="an-audit-sep">·</span><span className="an-audit-ip">{item.ip_address}</span></>
          )}
        </div>

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
const GroupedAuditEntry = ({ primary, details, isUnread, isExpanded, onToggle }) => {
  const id = primary?.audit_id ?? primary?.id;
  const userName =
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
            {primary?.created_at ? new Date(primary.created_at).toLocaleString() : ''}
          </span>
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
  const [allLowStock, setAllLowStock] = useState([]);
  const [allAudit, setAllAudit] = useState([]);
  const [pendingPOs, setPendingPOs] = useState([]);
  const [pendingProducts, setPendingProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [markingRead, setMarkingRead] = useState(false);
  const [stockPage, setStockPage] = useState(1);
  const [auditPage, setAuditPage] = useState(1);
  const [lastSeenMs, setLastSeenMs] = useState(0);
  const [expandedIds, setExpandedIds] = useState(new Set());

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
      try {
        const [lowStockRes, auditRes, pendingRes] = await Promise.all([
          dashboardAPI.getLowStock(),
          auditAPI.getTrail({ per_page: 200 }),
          dashboardAPI.getPendingApprovals(),
        ]);
        const stockPayload = Array.isArray(lowStockRes?.data) ? lowStockRes.data : [];
        const auditPayload = Array.isArray(auditRes?.data?.data)
          ? auditRes.data.data
          : Array.isArray(auditRes?.data) ? auditRes.data : [];
        const pendingData = pendingRes?.data?.data ?? pendingRes?.data ?? {};
        if (!mounted) return;
        setAllLowStock(stockPayload);
        setAllAudit(auditPayload);
        setPendingPOs(Array.isArray(pendingData.pending_pos) ? pendingData.pending_pos : []);
        setPendingProducts(Array.isArray(pendingData.pending_products) ? pendingData.pending_products : []);
      } catch (e) {
        if (mounted) toast.error(e.message || 'Failed to load alerts.');
      } finally {
        if (mounted) setLoading(false);
      }
    };
    loadAlerts();
    return () => { mounted = false; };
  }, []);

  const groupedAudit = useMemo(() => groupRawAuditLogs(allAudit), [allAudit]);

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

  const auditTotal = groupedAudit.length;
  const auditTotalPages = Math.ceil(auditTotal / PAGE_SIZE) || 1;
  const auditVisible = groupedAudit.slice((auditPage - 1) * PAGE_SIZE, auditPage * PAGE_SIZE);

  return (
    <AdminLayout>
      <div className="an-page">
        <div className="an-page-header">
          <div className="an-page-header-content">
            <h1 className="an-page-title">Alerts &amp; Notices</h1>
            <p className="an-page-subtitle">
              System alerts and activity — all actions and changes are recorded here.
            </p>
          </div>
          <button
            type="button"
            className="an-mark-all-read-btn"
            onClick={handleMarkAllRead}
            disabled={markingRead || (pendingPOs.length + pendingProducts.length + unreadCount) === 0}
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="16" height="16" aria-hidden>
              <polyline points="20 6 9 17 4 12" />
            </svg>
            {markingRead ? 'Marking...' : 'Mark All Read'}
          </button>
        </div>

        {/* Pending approvals */}
        <div className="an-card">
          <div className="an-card-header--row">
            <div>
              <h3 className="an-card-title">
                Pending approvals
                {!loading && (pendingPOs.length + pendingProducts.length) > 0 && (
                  <span className="an-pending-badge">{pendingPOs.length + pendingProducts.length}</span>
                )}
              </h3>
              <p className="an-card-subtitle">Items waiting for your approval</p>
            </div>
            <button type="button" className="an-go-queue-btn" onClick={() => navigate('/admin/approval-queue')}>
              Open approval queue
            </button>
          </div>

          {!loading && (pendingPOs.length + pendingProducts.length) > 0 && (
            <div className="an-alert-banner">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" width="18" height="18" aria-hidden>
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12.01" y2="17" />
              </svg>
              <span>
                <strong>{pendingPOs.length + pendingProducts.length} item{(pendingPOs.length + pendingProducts.length) !== 1 ? 's' : ''}</strong> require your approval.
                Review and act on them as soon as possible.
              </span>
            </div>
          )}

          <div className="an-approval-list">
            {loading ? (
              <div className="an-empty">Loading pending approvals...</div>
            ) : pendingPOs.length === 0 && pendingProducts.length === 0 ? (
              <div className="an-empty">No pending approvals at this time</div>
            ) : (
              <>
                {pendingPOs.length > 0 && (
                  <>
                    <div className="an-approval-group-label">
                      Purchase Orders <span className="an-approval-group-count">{pendingPOs.length}</span>
                    </div>
                    {pendingPOs.map((po) => (
                      <div className="an-approval-item an-approval-item--urgent" key={`po-${po.id}`}>
                        <div className="an-approval-left">
                          <span className="an-approval-title">
                            {po.po_number || `PO #${po.id}`}
                            {po.supplier && <span className="an-approval-supplier"> — {po.supplier}</span>}
                          </span>
                          <span className="an-approval-detail">
                            {po.order_date && <>Order date: {po.order_date} &bull; </>}
                            {po.total_amount > 0 && <>Amount: ₱{Number(po.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })} &bull; </>}
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
                            {p.category && <> &bull; Category: {p.category}</>}
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
              <div className="an-empty">Loading alerts...</div>
            ) : allLowStock.length === 0 ? (
              <div className="an-empty">No low-stock alerts at the moment</div>
            ) : (
              stockVisible.map((item, idx) => (
                <div className="an-approval-item" key={item.product_id || item.id || idx}>
                  <div className="an-approval-left">
                    <span className="an-approval-title">
                      {item.product_name || item.name || item.product?.product_name || `Item ${idx + 1}`}
                    </span>
                    <span className="an-approval-detail">
                      &bull; On hand: {Number(item.quantity_on_hand ?? item.quantity ?? 0).toLocaleString('en-PH')}
                      &nbsp;&bull; Reorder level: {Number(item.recommended_stocks ?? item.reorder_level ?? 0).toLocaleString('en-PH')}
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

        {/* Audit trail activity */}
        <div className="an-card">
          <div className="an-card-header">
            <h3 className="an-card-title">
              Audit Trail — System Activity
              {unreadCount > 0 && <span className="an-unread-badge">{unreadCount} new</span>}
            </h3>
            <p className="an-card-subtitle">Click any entry to expand or collapse details</p>
          </div>
          <div className="an-approval-list">
            {loading ? (
              <div className="an-empty">Loading...</div>
            ) : groupedAudit.length === 0 ? (
              <div className="an-empty">No audit entries yet</div>
            ) : (
              auditVisible.map((group, idx) => {
                const gId = group.primary?.audit_id ?? group.primary?.id ?? idx;
                const expanded = expandedIds.has(gId);
                const unread = isUnread(group.primary?.created_at);
                return group.type === 'grouped' ? (
                  <GroupedAuditEntry
                    key={gId}
                    primary={group.primary}
                    details={group.details}
                    isUnread={unread}
                    isExpanded={expanded}
                    onToggle={toggleExpanded}
                  />
                ) : (
                  <SingleAuditEntry
                    key={gId}
                    item={group.primary}
                    isUnread={unread}
                    isExpanded={expanded}
                    onToggle={toggleExpanded}
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
      </div>
    </AdminLayout>
  );
};

export default AlertsNotices;
