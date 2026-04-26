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
 * Role-specific sidebar menus.
 * Each item: { name, path, icon }
 * Admin uses the full menuSections array in AdminLayout directly.
 */
export const ROLE_MENUS = {
  [ROLES.ADMIN]: null, // null = show all sections (handled in AdminLayout)

  [ROLES.INVENTORY_ANALYST]: [
    {
      label: 'PROCUREMENT & PLANNING',
      items: [
        { name: 'Dashboard', path: '/admin/dashboard', icon: 'dashboard' },
        {
          name: 'Item Registration Approval',
          path: '/admin/approval-queue',
          to: '/admin/approval-queue?tab=item-registration',
          icon: 'approval',
        },
        { name: 'Purchase Orders', path: '/admin/purchase-orders', icon: 'purchaseorders' },
        { name: 'PO Recommendation', path: '/admin/po-recommendations', icon: 'po' },
        { name: 'Draft PO Creator', path: '/admin/draft-po-creator', icon: 'procurement' },
        { name: 'Supplier Network', path: '/admin/suppliers', icon: 'supplier' },
        { name: 'Item Master', path: '/admin/items', icon: 'items' },
        { name: 'Categories & Brands', path: '/admin/categories', icon: 'category' },
      ],
    },
    {
      label: 'INVENTORY INTELLIGENCE',
      items: [
        { name: 'Stock Movement', path: '/admin/inventory', icon: 'movement' },
        { name: 'Inventory Monitoring', path: '/admin/inventory', icon: 'inventory' },
      ],
    },
    {
      label: 'REPORTS / COMPLIANCE',
      items: [
        { name: 'Transactions', path: '/admin/transactions', icon: 'globaltransactions' },
        { name: 'Audit Trail', path: '/admin/audit', icon: 'audit' },
        { name: 'System Reports', path: '/admin/reports', icon: 'reports' },
      ],
    },
  ],

  [ROLES.AUDITOR]: [
    {
      label: 'DASHBOARD',
      items: [
        { name: 'Dashboard', path: '/admin/dashboard', icon: 'dashboard' },
      ],
    },
    {
      label: 'ARCHIVE MANAGEMENT',
      items: [
        { name: 'Archive Management', path: '/admin/archive', icon: 'archive' },
      ],
    },
    {
      label: 'INVENTORY INTELLIGENCE',
      items: [
        { name: 'Stock Movement', path: '/admin/inventory', icon: 'movement' },
      ],
    },
    {
      label: 'COMPLIANCE & SUPPORT',
      items: [
        { name: 'Compliance Review', path: '/admin/compliance', icon: 'compliance' },
        { name: 'Transactions', path: '/admin/transactions', icon: 'globaltransactions' },
        { name: 'System Reports', path: '/admin/reports', icon: 'reports' },
        { name: 'Audit Trail', path: '/admin/audit', icon: 'audit' },
      ],
    },
    {
      label: 'SALES & LOGISTICS',
      items: [
        { name: 'Inventory Monitoring', path: '/admin/inventory', icon: 'inventory' },
      ],
    },
  ],

  [ROLES.BRANCH_MANAGER]: [
    {
      label: 'DASHBOARD',
      items: [
        { name: 'Dashboard', path: '/admin/dashboard', icon: 'dashboard' },
        {
          name: 'Item Registration Approval',
          path: '/admin/approval-queue',
          to: '/admin/approval-queue?tab=item-registration',
          icon: 'approval',
        },
        { name: 'Purchase Orders', path: '/admin/purchase-orders', icon: 'purchaseorders' },
      ],
    },
    {
      label: 'PROCUREMENT & PLANNING',
      items: [
        { name: 'PO Recommendation', path: '/admin/po-recommendations', icon: 'po' },
        { name: 'POS Operations', path: '/admin/transactions', icon: 'transactions' },
        { name: 'Draft PO Creator', path: '/admin/draft-po-creator', icon: 'procurement' },
      ],
    },
    {
      label: 'SALES & REPORTS',
      items: [
        { name: 'POS Operations', path: '/admin/transactions', icon: 'transactions' },
        { name: 'Customer Registry', path: '/admin/customers', icon: 'customers' },
        { name: 'Inventory Operation', path: '/admin/inventory-operation', icon: 'inventoryop' },
        { name: 'Transactions', path: '/admin/transfers', icon: 'transfer' },
      ],
    },
    {
      label: 'INVENTORY MOVEMENT',
      items: [
        { name: 'Stock Movement', path: '/admin/inventory', icon: 'movement' },
      ],
    },
    {
      label: 'COMPLIANCE & SUPPORT',
      items: [
        { name: 'System Reports', path: '/admin/reports', icon: 'reports' },
      ],
    },
  ],

  [ROLES.WAREHOUSE_PERSONNEL]: [
    {
      label: 'DASHBOARD',
      items: [
        { name: 'Dashboard', path: '/admin/dashboard', icon: 'dashboard' },
      ],
    },
    {
      label: 'INVENTORY EXECUTION',
      items: [
        { name: 'Receivings', path: '/admin/receivings', icon: 'purchaseorders' },
        { name: 'Purchase Orders', path: '/admin/purchase-orders', icon: 'purchaseorders' },
        { name: 'Inventory Operation', path: '/admin/inventory-operation', icon: 'barcode' },
        { name: 'Stock Movement', path: '/admin/inventory', icon: 'movement' },
        { name: 'Inventory Monitoring', path: '/admin/inventory', icon: 'inventory' },
        { name: 'Transactions (With Transfers)', path: '/admin/transfers', icon: 'transfer' },
      ],
    },
  ],
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
  '/admin/roles': [ROLES.ADMIN],
  '/admin/config': [ROLES.ADMIN],
  '/admin/archive': [ROLES.ADMIN, ROLES.AUDITOR],
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
  '/admin/po-recommendations': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/draft-po-creator': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],
  '/admin/purchase-returns': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],

  // POS / sales
  '/admin/customers': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/transactions': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/delivery-receipts': [ROLES.ADMIN, ROLES.BRANCH_MANAGER, ROLES.WAREHOUSE_PERSONNEL, ROLES.AUDITOR],
  '/admin/profit-loss': [ROLES.ADMIN, ROLES.INVENTORY_ANALYST, ROLES.BRANCH_MANAGER, ROLES.AUDITOR],

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
