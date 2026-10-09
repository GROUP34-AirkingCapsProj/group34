/** Frontend navigation permissions. Combined pages may accept anyRequired; each tab enforces its own permission. */
export const NAV_BUNDLES = [
  { name: 'Dashboard',             routePath: '/admin/dashboard',        required: ['dashboard_access.read'] },
  { name: 'Analytics Dashboard',   routePath: '/admin/analytics',        required: ['analytics_dashboard.read'] },
  { name: 'Role & Permissions',    routePath: '/admin/roles',            required: ['role_assignment.read'] },
  { name: 'Permission Audit',      routePath: '/admin/permission-audit', required: ['permission_audit.read'] },
  { name: 'Location Management',   routePath: '/admin/branches',         required: ['location_based_inventory.read'] },
  { name: 'System Configuration',  routePath: '/admin/config',           required: ['system_configuration.read'] },
  { name: 'Archive Management',    routePath: '/admin/archive',          required: ['archive_viewing.read'] },
  { name: 'Budget Management',     routePath: '/admin/budget-management',required: ['budget_verification.read'] },
  { name: 'Purchase Requests',     routePath: '/admin/purchase-requests',required: ['purchase_request_creation.read'] },
  { name: 'Purchase Orders',       routePath: '/admin/draft-po-creator', required: ['purchase_order_creation.read'] },
  { name: 'Supplier Network',      routePath: '/admin/suppliers',        required: ['supplier_management.read'] },
  { name: 'Item Master',           routePath: '/admin/items',            required: ['item_master_management.read'] },
  { name: 'Item Lookups',          routePath: '/admin/categories',       required: ['lookup_management.read'] },
  { name: 'Set Management',        routePath: '/admin/sets',             required: ['product_set_management.read'] },
  { name: 'Aircon Models',         routePath: '/admin/aircon-models',    required: ['aircon_model_management.read'] },
  { name: 'Inventory Monitoring',  routePath: '/admin/inventory',        required: ['inventory_monitoring.read'] },
  { name: 'Transfer Requests',     routePath: '/admin/transfer-requests',required: ['inventory_transfers.read'] },
  { name: 'Stock Transfers',       routePath: '/admin/transfers',        required: ['stock_transfers.read'] },
  { name: 'Interbranch Transfers', routePath: '/admin/ist',              required: ['inventory_transfers.read'] },
  { name: 'Incident Reports',      routePath: '/admin/incidents',        required: ['incident_reports.read'] },
  { name: 'Inspections',           routePath: '/admin/inspections',      required: ['incident_reports.read'] },
  { name: 'Incident Items',        routePath: '/admin/incident-items',   required: ['incident_reports.read'] },
  { name: 'Transactions',          routePath: '/admin/transactions',     required: ['sales_recording.read'] },
  { name: 'Customer Registry',     routePath: '/admin/customers',        required: ['customer_management.read'] },
  { name: 'Scan Barcode',          routePath: '/admin/inventory-operation', required: ['barcode_inventory_processing.create'] },
  { name: 'Compliance Review',     routePath: '/admin/compliance',       required: ['exception_detection.read'] },
  { name: 'Warranty Records',      routePath: '/admin/warranties',       required: ['warranty_records.read'] },
  { name: 'Audit Trail',           routePath: '/admin/audit',            required: ['audit_trail_viewing.read'] },
  { name: 'Audit & Evidence',     routePath: '/admin/audit-evidence',   required: ['audit_evidence.read'] },
  { name: 'Reports & Analytics',   routePath: '/admin/reports',          required: ['report_viewing.read'], anyRequired: ['report_viewing.read', 'analytics_dashboard.read'] },
  { name: 'Alerts & Notices',      routePath: '/admin/alerts',           required: ['notification_panel.read'] },
];

/**
 * Routes that are permission-gated but intentionally NOT shown in the sidebar.
 * Map of routePath -> required permission key. RoleRoute enforces these the same
 * way it enforces NAV_BUNDLES, so access stays permission-driven even without a
 * sidebar entry. (Warranty Claims is reached via the "Claim Warranty" button on
 * the Warranty Records page, not the sidebar.)
 *
 * Report Incident is reached from the "Report Incident" tile on the Inventory
 * Operation scan page. It was in neither this map nor ROUTE_ACCESS, so the
 * role fallback in canAccessRoute treated it as admin-only and bounced every
 * warehouse personnel back to the dashboard — even though the backend grants
 * them incident_reports.create.
 */
export const PERMISSION_GATED_ROUTES = {
  '/admin/warranty-claims': 'warranty_claims.create',
  '/admin/barcode-adjustment': 'incident_reports.create',
};

const granted = (perms, key) => perms != null && perms[key] === true;

export function bundleVisible(bundle, perms) {
  if (bundle?.anyRequired?.length) return bundle.anyRequired.some((k) => granted(perms, k));
  if (!bundle || !bundle.required || bundle.required.length === 0) return false;
  return bundle.required.every((k) => granted(perms, k));
}
