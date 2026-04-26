export const APPROVAL_QUEUE_STORAGE_KEY = 'approval_queue_purchase_orders';
const APPROVAL_QUEUE_KEY = APPROVAL_QUEUE_STORAGE_KEY;
const RESTOCK_QUEUE_KEY = 'approval_queue_restock_requests';
const NOTIFICATIONS_KEY = 'system_notifications';

const safeParse = (raw, fallback) => {
  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : fallback;
  } catch (_) {
    return fallback;
  }
};

const emitUpdate = () => {
  window.dispatchEvent(new CustomEvent('approval-notifications-updated'));
};

const nowIso = () => new Date().toISOString();

export const getApprovalQueuePurchaseOrders = () => {
  return safeParse(localStorage.getItem(APPROVAL_QUEUE_KEY), []);
};

export const saveApprovalQueuePurchaseOrders = (items) => {
  localStorage.setItem(APPROVAL_QUEUE_KEY, JSON.stringify(items));
  emitUpdate();
};

export const addApprovalQueuePurchaseOrder = (po) => {
  const current = getApprovalQueuePurchaseOrders();
  const next = [{ ...po, created_at: po.created_at || nowIso() }, ...current];
  saveApprovalQueuePurchaseOrders(next);
  return next;
};

export const updateApprovalQueuePurchaseOrderStatus = (id, status) => {
  const current = getApprovalQueuePurchaseOrders();
  const next = current.map((po) =>
    String(po.id) === String(id)
      ? {
          ...po,
          status,
          ...(status === 'authorized' ? { approved_at: nowIso() } : {}),
        }
      : po
  );
  saveApprovalQueuePurchaseOrders(next);
  return next;
};

/** Merge fields onto a queued PO (e.g. after API sync). */
export const mergeApprovalQueuePurchaseOrder = (id, patch) => {
  const current = getApprovalQueuePurchaseOrders();
  const next = current.map((po) =>
    String(po.id) === String(id) ? { ...po, ...patch } : po
  );
  saveApprovalQueuePurchaseOrders(next);
  return next;
};

export const getSystemNotifications = () => {
  return safeParse(localStorage.getItem(NOTIFICATIONS_KEY), []);
};

export const saveSystemNotifications = (items) => {
  localStorage.setItem(NOTIFICATIONS_KEY, JSON.stringify(items));
  emitUpdate();
};

export const addSystemNotification = (notification) => {
  const current = getSystemNotifications();
  const next = [
    {
      id: notification.id || `ntf-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      created_at: nowIso(),
      read: false,
      type: 'info',
      ...notification,
    },
    ...current,
  ];
  saveSystemNotifications(next);
  return next;
};

export const getUnreadNotificationCount = () => {
  return getSystemNotifications().filter((n) => !n.read).length;
};

export const markAllNotificationsRead = () => {
  const next = getSystemNotifications().map((n) => ({ ...n, read: true }));
  saveSystemNotifications(next);
  return next;
};

/** Mark one in-app notification read (e.g. after opening from Alerts & Notices). */
export const markNotificationRead = (id) => {
  const sid = String(id);
  const next = getSystemNotifications().map((n) =>
    String(n.id) === sid ? { ...n, read: true } : n
  );
  saveSystemNotifications(next);
  return next;
};

export const getApprovalQueueRestockRequests = () => {
  return safeParse(localStorage.getItem(RESTOCK_QUEUE_KEY), []);
};

export const saveApprovalQueueRestockRequests = (items) => {
  localStorage.setItem(RESTOCK_QUEUE_KEY, JSON.stringify(items));
  emitUpdate();
};

export const addApprovalQueueRestockRequest = (request) => {
  const current = getApprovalQueueRestockRequests();
  const next = [{ ...request, created_at: request.created_at || nowIso() }, ...current];
  saveApprovalQueueRestockRequests(next);
  return next;
};

export const updateApprovalQueueRestockRequestStatus = (id, status) => {
  const current = getApprovalQueueRestockRequests();
  const next = current.map((req) =>
    String(req.id) === String(id) ? { ...req, status } : req
  );
  saveApprovalQueueRestockRequests(next);
  return next;
};

