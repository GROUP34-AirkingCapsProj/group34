/** Frontend navigation permissions. Combined pages may accept anyRequired; each tab enforces its own permission. */
export const NAV_BUNDLES = [
  { name: 'Dashboard',             routePath: '/dashboard',        required: ['dashboard_access.read'] },
  { name: 'Analytics Dashboard',   routePath: '/analytics',        required: ['analytics_dashboard.read'] },
  { name: 'Role & Permissions',    routePath: '/roles',            required: ['role_assignment.read'] },
  { name: 'Permission Audit',      routePath: '/permission-audit', required: ['permission_audit.read'] },
  { name: 'Location Management',   routePath: '/branches',         required: ['location_based_inventory.read'] },
  { name: 'System Configuration',  routePath: '/config',           required: ['system_configuration.read'] },
  { name: 'Archive Management',    routePath: '/archive',          required: ['archive_viewing.read'] },
  { name: 'Purchase Requests',     routePath: '/purchase-requests',required: ['purchase_request_creation.read'] },
  { name: 'Purchase Orders',       routePath: '/draft-po-creator', required: ['purchase_order_creation.read'] },
  { name: 'Supplier Network',      routePath: '/suppliers',        required: ['supplier_management.read'] },
  { name: 'Item Master',           routePath: '/items',            required: ['item_master_management.read'] },
  { name: 'Item Lookups',          routePath: '/categories',       required: ['lookup_management.read'] },
  { name: 'Set Management',        routePath: '/sets',             required: ['product_set_management.read'] },
  { name: 'Aircon Models',         routePath: '/aircon-models',    required: ['aircon_model_management.read'] },
  { name: 'Inventory Monitoring',  routePath: '/inventory',        required: ['inventory_monitoring.read'] },
  { name: 'Transfer Requests',     routePath: '/transfer-requests',required: ['inventory_transfers.read'] },
  { name: 'Stock Transfers',       routePath: '/transfers',        required: ['stock_transfers.read'] },
  { name: 'Incident Reports',      routePath: '/incidents',        required: ['incident_reports.read'] },
  { name: 'Transactions',          routePath: '/transactions',     required: ['sales_recording.read'] },
  { name: 'Customer Registry',     routePath: '/customers',        required: ['customer_management.read'] },
  { name: 'Scan Barcode',          routePath: '/inventory-operation', required: ['barcode_inventory_processing.create'] },
  { name: 'Compliance Review',     routePath: '/compliance',       required: ['exception_detection.read'] },
  { name: 'Warranty Records',      routePath: '/warranties',       required: ['warranty_records.read'] },
  { name: 'Audit Trail',           routePath: '/audit',            required: ['audit_trail_viewing.read'] },
  { name: 'Audit & Evidence',     routePath: '/audit-evidence',   required: ['audit_evidence.read'] },
  { name: 'Reports & Analytics',   routePath: '/reports',          required: ['report_viewing.read'], anyRequired: ['report_viewing.read', 'analytics_dashboard.read'] },
  { name: 'Alerts & Notices',      routePath: '/alerts',           required: ['notification_panel.read'] },
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
  '/warranty-claims': 'warranty_claims.create',
  '/barcode-adjustment': 'incident_reports.create',
};

const granted = (perms, key) => perms != null && perms[key] === true;

export function bundleVisible(bundle, perms) {
  if (bundle?.anyRequired?.length) return bundle.anyRequired.some((k) => granted(perms, k));
  if (!bundle || !bundle.required || bundle.required.length === 0) return false;
  return bundle.required.every((k) => granted(perms, k));
}
