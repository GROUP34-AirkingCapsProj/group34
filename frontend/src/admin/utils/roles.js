/**
 * Role-Based Access Control helpers
 * Role names come from the DB seeder: admin, inventory_analyst, branch_manager, warehouse_personnel, auditor
 */

export const ROLES = {
  ADMIN: 'admin',
  INVENTORY_ANALYST: 'inventory_analyst',
  BRANCH_MANAGER: 'branch_manager',
  WAREHOUSE_PERSONNEL: 'warehouse_personnel',
  AUDITOR: 'auditor',
};

/** Normalize whatever the API returns into a known key */
export const getRoleKey = (user) => {
  const raw = user?.role?.role_name || user?.role || '';
  const normalized = String(raw).toLowerCase().trim();
  const exact = Object.values(ROLES).find((r) => r === normalized);
  if (exact) return exact;
  // e.g. "branch manager" or "inventory analyst" from legacy/display values
  const underscored = normalized.replace(/\s+/g, '_');
  return Object.values(ROLES).find((r) => r === underscored) || null;
};

export const ROLE_META = {
  [ROLES.ADMIN]: {
    label: 'System Administrator',
    accent: '#dc2626',        // red (existing brand)
    dashboardTitle: 'System Dashboard',
    dashboardSubtitle: 'System-wide statistics and overall health monitoring.',
  },
  [ROLES.INVENTORY_ANALYST]: {
    label: 'Inventory Analyst',
    accent: '#7c3aed',        // purple
    dashboardTitle: 'Inventory Analytics',
    dashboardSubtitle: 'Real-time inventory performance, stock valuation, and trend analysis.',
  },
  [ROLES.BRANCH_MANAGER]: {
    label: 'Branch Manager',
    accent: '#059669',        // green
    dashboardTitle: 'Branch Dashboard',
    dashboardSubtitle: 'Monitor branch-level operations, transactions, and inventory.',
  },
  [ROLES.WAREHOUSE_PERSONNEL]: {
    label: 'Warehouse Personnel',
    accent: '#2563eb',        // blue
    dashboardTitle: 'Warehouse Operations',
    dashboardSubtitle: 'Daily tasks, receiving, transfers, and assigned inventory at a glance.',
  },
  [ROLES.AUDITOR]: {
    label: 'Auditor',
    accent: '#d97706',        // amber/orange
    dashboardTitle: 'Audit Dashboard',
    dashboardSubtitle: 'System audit logs, user activity, and compliance monitoring.',
  },
};

/**
 * Route-level RBAC (frontend guard).
 * Keep this in sync with backend route middleware.
 */
export const ROUTE_ACCESS = {
  // Core
  '/admin/dashboard': Object.values(ROLES),
  '/admin/analytics': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/reports': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/audit': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/compliance': Object.values(ROLES),
  '/admin/alerts': [ROLES.ADMIN],
  '/admin/notifications': [ROLES.ADMIN],

  // Admin/security
  '/admin/users': [ROLES.ADMIN],
  '/admin/config': [ROLES.ADMIN],
  '/admin/archive': [ROLES.ADMIN, ROLES.AUDITOR],
  '/admin/archive-settings': [ROLES.ADMIN],
  // Approval Queue: analysts approve item registration; auditors read-only; PO/restock actions: admin & branch manager (canActOnApprovalQueue).
  '/admin/approval-queue': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.AUDITOR, ROLES.INVENTORY_ANALYST],

  // Item/barcode/classification
  '/admin/items': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/pending-consumables': [ROLES.ADMIN, ROLES.BRANCH_MANAGER],
  '/admin/categories': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.AUDITOR],
  '/admin/brands': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.AUDITOR],
  '/admin/inventory-operation': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],
  '/admin/scan': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],

  // Inventory operations
  '/admin/inventory': Object.values(ROLES),
  '/admin/transfers': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/receivings': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/issuances': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/adjustments': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],

  // Purchase Orders (Approval Queue — PO tab: upcoming / pending; warehouse is view-only there)
  '/admin/purchase-orders': [
    ROLES.ADMIN,
    ROLES.INVENTORY_ANALYST,
    ROLES.BRANCH_MANAGER,
    ROLES.WAREHOUSE_PERSONNEL,
    ROLES.AUDITOR,
  ],

  // Procurement & restocking
  '/admin/suppliers': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/budget-management': [ROLES.ADMIN],
  '/admin/purchase-requests': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST],
  '/admin/po-recommendations': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/draft-po-creator': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/purchase-returns': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],

  // POS / sales
  '/admin/customers': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/transactions': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/delivery-receipts': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/profit-loss': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],

  // Issuance scan (barcode-driven issuance)
  '/admin/issuance-scan': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],

  // Warranties
  '/admin/warranties': Object.values(ROLES),

  // SA Permission Editor
  '/admin/roles': [ROLES.ADMIN],
  '/admin/user-permissions': [ROLES.ADMIN],
  '/admin/permission-audit': [ROLES.ADMIN],

  // Legacy pages
  '/admin/branches': [ROLES.ADMIN],
  '/admin/warehouses': [ROLES.ADMIN],
  '/admin/warranty': [ROLES.ADMIN],

};

export const canAccessRoute = (user, path) => {
  const role = getRoleKey(user);
  if (!role) return false;
  const allowed = ROUTE_ACCESS[path];
  if (!allowed) return role === ROLES.ADMIN;
  return allowed.includes(role);
};

/** Approve / reject in Approval Queue (PO + restock local queue). Admin & branch manager only. */
export const canActOnApprovalQueue = (user) => {
  const r = getRoleKey(user);
  return r === ROLES.ADMIN || r === ROLES.BRANCH_MANAGER;
};

/** Approve pending product registrations (Item registration tab) — matches API pending-products/approve. */
export const canApprovePendingRegistrations = (user) => {
  const r = getRoleKey(user);
  return r === ROLES.ADMIN || r === ROLES.BRANCH_MANAGER;
};
