import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import { auditAPI, dashboardAPI } from '../services/api';
import { toast } from '../utils/toast';
import {
  getSystemNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  getApprovalQueuePurchaseOrders,
  getApprovalQueueRestockRequests,
} from '../utils/approvalNotifications';
import '../styles/dashboard_air.css';
import '../styles/alerts_notices.css';

const AlertsNotices = () => {
  const navigate = useNavigate();
  const [lowStockItems, setLowStockItems] = useState([]);
  const [auditNotifications, setAuditNotifications] = useState([]);
  const [systemNotifications, setSystemNotifications] = useState([]);
  const [pendingPoCount, setPendingPoCount] = useState(0);
  const [pendingRestockCount, setPendingRestockCount] = useState(0);
  const [loading, setLoading] = useState(true);

  const refreshLocalQueues = useCallback(() => {
    const pos = getApprovalQueuePurchaseOrders();
    const restocks = getApprovalQueueRestockRequests();
    setPendingPoCount(pos.filter((p) => String(p.status || '').toLowerCase() === 'pending').length);
    setPendingRestockCount(restocks.filter((r) => String(r.status || '').toLowerCase() === 'pending').length);
    setSystemNotifications(getSystemNotifications());
  }, []);

  useEffect(() => {
    let mounted = true;
    const loadAlerts = async () => {
      setLoading(true);
      try {
        const [lowStockRes, auditRes] = await Promise.all([
          dashboardAPI.getLowStock(),
          auditAPI.getTrail({ per_page: 20 }),
        ]);
        const stockPayload = Array.isArray(lowStockRes?.data) ? lowStockRes.data : [];
        const auditPayload = Array.isArray(auditRes?.data?.data)
          ? auditRes.data.data
          : Array.isArray(auditRes?.data)
            ? auditRes.data
            : [];

        if (!mounted) return;

        setLowStockItems(stockPayload.slice(0, 10));
        setAuditNotifications(auditPayload.slice(0, 10).map((item) => {
          const userName = `${item?.user?.first_name || ''} ${item?.user?.last_name || ''}`.trim()
            || item?.user?.username
            || 'System';
          return {
            id: item?.audit_id || item?.id || Math.random(),
            title: `${String(item?.action || 'activity').toUpperCase()} - ${item?.table_name || 'system'}`,
            description: `${userName} · ${item?.created_at ? new Date(item.created_at).toLocaleString() : ''}`,
          };
        }));
        refreshLocalQueues();
      } catch (e) {
        const message = e.message || 'Failed to load alerts.';
        if (mounted) toast.error(message);
      } finally {
        if (mounted) setLoading(false);
      }
    };

    loadAlerts();
    return () => { mounted = false; };
  }, [refreshLocalQueues]);

  useEffect(() => {
    const onUpdate = () => refreshLocalQueues();
    window.addEventListener('approval-notifications-updated', onUpdate);
    window.addEventListener('storage', onUpdate);
    return () => {
      window.removeEventListener('approval-notifications-updated', onUpdate);
      window.removeEventListener('storage', onUpdate);
    };
  }, [refreshLocalQueues]);

  const handleMarkAllRead = () => {
    markAllNotificationsRead();
    refreshLocalQueues();
    window.dispatchEvent(new CustomEvent('approval-notifications-updated'));
  };

  const handleOpenSystemNotification = (n) => {
    markNotificationRead(n.id);
    refreshLocalQueues();
    window.dispatchEvent(new CustomEvent('approval-notifications-updated'));
    if (n.route) {
      navigate(n.route);
    }
  };

  return (
    <AdminLayout>
      <div className="an-page">
        <div className="an-page-header">
          <h1 className="an-page-title">Alerts &amp; Notices</h1>
          <p className="an-page-subtitle">
            Administrator view: in-app notifications (header bell), approval queue summary, low stock, and audit activity.
          </p>
        </div>

        {/* Same entries as the admin header notification bell */}
        <div className="an-card">
          <div className="an-card-header an-card-header--row">
            <div>
              <h3 className="an-card-title">In-app notifications</h3>
              <p className="an-card-subtitle">Restock requests and other alerts pushed from Draft PO / workflow</p>
            </div>
            {systemNotifications.some((n) => !n.read) && (
              <button type="button" className="an-mark-read-btn" onClick={handleMarkAllRead}>
                Mark all read
              </button>
            )}
          </div>
          <div className="an-approval-list">
            {systemNotifications.length === 0 ? (
              <div className="an-empty">No in-app notifications</div>
            ) : (
              systemNotifications.map((n) => (
                <button
                  key={n.id}
                  type="button"
                  className={`an-approval-item an-ntf-item${n.read ? ' an-ntf-item--read' : ''}`}
                  onClick={() => handleOpenSystemNotification(n)}
                >
                  <div className="an-approval-left an-ntf-text">
                    <span className="an-approval-title">{n.title || 'Notification'}</span>
                    {n.description && (
                      <span className="an-approval-detail">&bull; {n.description}</span>
                    )}
                    {n.details && (
                      <span className="an-approval-detail">&bull; {n.details}</span>
                    )}
                    {n.route && (
                      <span className="an-ntf-hint">Open linked page →</span>
                    )}
                  </div>
                </button>
              ))
            )}
          </div>
        </div>

        <div className="an-card">
          <div className="an-card-header">
            <h3 className="an-card-title">Pending approvals</h3>
            <p className="an-card-subtitle">Items waiting in the local approval queue</p>
          </div>
          <div className="an-approval-list">
            <div className="an-approval-item an-pending-summary">
              <div className="an-approval-left">
                <span className="an-approval-title">Purchase orders &amp; restock</span>
                <span className="an-approval-detail">
                  &bull; {pendingPoCount} PO(s) pending &nbsp;&bull; {pendingRestockCount} restock request(s) pending
                </span>
              </div>
              <div className="an-approval-right">
                <button type="button" className="an-go-queue-btn" onClick={() => navigate('/admin/approval-queue')}>
                  Open approval queue
                </button>
              </div>
            </div>
          </div>
        </div>

        <div className="an-card">
          <div className="an-card-header">
            <h3 className="an-card-title">Low stock alerts</h3>
            <p className="an-card-subtitle">Products at or below reorder level</p>
          </div>
          <div className="an-approval-list">
            {loading ? (
              <div className="an-empty">Loading alerts...</div>
            ) : lowStockItems.length === 0 ? (
              <div className="an-empty">No low-stock alerts at the moment</div>
            ) : (
              lowStockItems.map((item, idx) => (
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
        </div>

        <div className="an-card">
          <div className="an-card-header">
            <h3 className="an-card-title">Recent audit activity</h3>
            <p className="an-card-subtitle">Latest entries from the audit trail</p>
          </div>
          <div className="an-approval-list">
            {loading ? (
              <div className="an-empty">Loading...</div>
            ) : auditNotifications.length === 0 ? (
              <div className="an-empty">No audit entries yet</div>
            ) : (
              auditNotifications.map((item) => (
                <div className="an-approval-item" key={item.id}>
                  <div className="an-approval-left">
                    <span className="an-approval-title">{item.title || 'System update'}</span>
                    <span className="an-approval-detail">&bull; {item.description || item.details || '—'}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </AdminLayout>
  );
};

export default AlertsNotices;
