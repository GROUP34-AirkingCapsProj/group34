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
  '/dashboard': Object.values(ROLES),
  '/analytics': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/reports': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/audit': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/compliance': Object.values(ROLES),
  '/alerts': [ROLES.ADMIN],
  '/notifications': [ROLES.ADMIN],

  // Admin/security
  '/config': [ROLES.ADMIN],
  '/archive': [ROLES.ADMIN, ROLES.AUDITOR],
  '/archive-settings': [ROLES.ADMIN],
  '/backup': [ROLES.ADMIN],

  // Item/barcode/classification
  '/items': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/pending-installation-materials': [ROLES.ADMIN, ROLES.BRANCH_MANAGER],
  '/categories': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.AUDITOR],
  '/brands': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.AUDITOR],
  '/inventory-operation': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],
  '/scan': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],
  /* Reprinting a damaged label is warehouse work, so it matches the scan
     pages rather than the read-only reports. The queue endpoints behind it
     are auth-only and already scoped to the user's location; an unlisted
     path here would fall back to admin-only and hide the page from exactly
     the people who print. */
  '/barcode-printing': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],

  // Inventory operations
  '/inventory': Object.values(ROLES),
  '/transfers': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/issuances': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/adjustments': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],

  // Purchase Orders (Approval Queue — PO tab: upcoming / pending; warehouse is view-only there)
  '/purchase-orders': [
    ROLES.ADMIN,
    ROLES.INVENTORY_ANALYST,
    ROLES.BRANCH_MANAGER,
    ROLES.WAREHOUSE_PERSONNEL,
    ROLES.AUDITOR,
  ],

  // Procurement & restocking
  '/suppliers': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/purchase-requests': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST],
  '/draft-po-creator': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/purchase-returns': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],

  // POS / sales
  '/customers': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/transactions': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/delivery-receipts': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/profit-loss': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],

  // Issuance scan (barcode-driven issuance)
  '/issuance-scan': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL],

  // Warranties
  '/warranties': Object.values(ROLES),

  // SA Permission Editor
  '/roles': [ROLES.ADMIN],
  '/user-permissions': [ROLES.ADMIN],
  '/permission-audit': [ROLES.ADMIN],

  // Legacy pages
  '/branches': [ROLES.ADMIN],
  '/warehouses': [ROLES.ADMIN],
  '/warranty': [ROLES.ADMIN],

};

export const canAccessRoute = (user, path) => {
  const role = getRoleKey(user);
  if (!role) return false;
  const allowed = ROUTE_ACCESS[path];
  if (!allowed) return role === ROLES.ADMIN;
  return allowed.includes(role);
};

/** Approve pending product registrations (Item registration tab) — matches API pending-products/approve. */
export const canApprovePendingRegistrations = (user) => {
  const r = getRoleKey(user);
  return r === ROLES.ADMIN || r === ROLES.BRANCH_MANAGER;
};
