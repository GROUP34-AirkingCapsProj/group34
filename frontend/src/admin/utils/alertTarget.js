// frontend/src/admin/utils/alertTarget.js
// Single source of truth: maps an alert item to a navigation target
// { path, focusKey } where focusKey is "<type>:<id>", or null when not navigable.

const AUDIT_TABLE_TARGETS = {
  purchase_orders:   (id) => ({ path: '/admin/draft-po-creator', focusKey: `po:${id}` }),
  purchase_requests: (id) => ({ path: '/admin/purchase-requests', focusKey: `pr:${id}` }),
  transfers:         (id) => ({ path: '/admin/transfers',         focusKey: `transfer:${id}` }),
  receivings:        (id) => ({ path: '/admin/receivings',        focusKey: `receiving:${id}` }),
  products:          (id) => ({ path: '/admin/items',             focusKey: `product:${id}` }),
  inventory:         (id) => ({ path: '/admin/inventory',         focusKey: `inv:${id}` }),
};

export function pendingPoTarget(po) {
  const id = po?.id ?? po?.po_id;
  return id != null ? { path: '/admin/draft-po-creator', focusKey: `po:${id}` } : null;
}

export function pendingPrTarget(pr) {
  const id = pr?.id ?? pr?.pr_id;
  return id != null ? { path: '/admin/purchase-requests', focusKey: `pr:${id}` } : null;
}

// No wired approval destination yet — intentionally non-clickable.
export function pendingRegistrationTarget() {
  return null;
}

export function lowStockTarget(item) {
  const invId = item?.inventory_id;
  if (invId != null) return { path: '/admin/inventory', focusKey: `inv:${invId}` };
  const pid = item?.product_id;
  return pid != null ? { path: '/admin/items', focusKey: `product:${pid}` } : null;
}

export function auditTarget(entry) {
  if (!entry || entry.action === 'delete') return null;
  const builder = AUDIT_TABLE_TARGETS[entry.table_name];
  const id = entry.record_id;
  return builder && id != null ? builder(id) : null;
}

export function targetHref(target) {
  return target ? `${target.path}?focus=${encodeURIComponent(target.focusKey)}` : null;
}
