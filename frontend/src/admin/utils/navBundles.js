/**
 * Frontend mirror of NavBundleSeeder (back-end/database/seeders/NavBundleSeeder.php).
 * Keep in sync with that seeder. `required` = read gate; `actions` = bundled action perms.
 */
export const NAV_BUNDLES = [
  // ADMINISTRATION
  { name: 'Dashboard', routePath: '/admin/dashboard', section: 'ADMINISTRATION', icon: 'dashboard',
    required: ['role_dashboards.read'], actions: ['summary_cards.read', 'quick_actions.create'] },
  { name: 'Approval Queue', routePath: '/admin/approval-queue', section: 'ADMINISTRATION', icon: 'approval',
    required: ['purchase_order_approval.approve'], actions: ['restock_approval.approve', 'item_master_management.approve'] },
  { name: 'Analytics Dashboard', routePath: '/admin/analytics', section: 'ADMINISTRATION', icon: 'analytics',
    required: ['stock_level_analysis.read'], actions: ['consumption_analysis.read', 'inventory_turnover_analysis.read'] },
  { name: 'User Management', routePath: '/admin/users', section: 'ADMINISTRATION', icon: 'users',
    required: ['user_account_management.read'], actions: ['user_account_management.create', 'user_account_management.update', 'user_account_management.archive', 'role_assignment.update'] },
  { name: 'Role & Permissions', routePath: '/admin/roles', section: 'ADMINISTRATION', icon: 'roles',
    required: ['rbac_enforcement.read'], actions: ['role_assignment.update', 'rbac_enforcement.update'] },
  { name: 'User Overrides', routePath: '/admin/user-permissions', section: 'ADMINISTRATION', icon: 'roles',
    required: ['rbac_enforcement.read'], actions: ['rbac_enforcement.update'] },
  { name: 'Permission Audit', routePath: '/admin/permission-audit', section: 'ADMINISTRATION', icon: 'audit',
    required: ['audit_trail_viewing.read'], actions: [] },
  { name: 'Location Management', routePath: '/admin/branches', section: 'ADMINISTRATION', icon: 'location',
    required: ['location_based_inventory.read'], actions: ['location_based_inventory.create', 'location_based_inventory.update', 'location_based_inventory.archive'] },
  { name: 'Archive Management', routePath: '/admin/archive', section: 'ADMINISTRATION', icon: 'archive',
    required: ['archive_viewing.read'], actions: ['data_archiving.archive'] },
  // PROCUREMENT & PLANNING
  { name: 'Purchase Requests', routePath: '/admin/purchase-requests', section: 'PROCUREMENT & PLANNING', icon: 'approval',
    required: ['purchase_request_creation.read'], actions: ['purchase_request_creation.create', 'purchase_request_approval.approve'] },
  { name: 'Purchase Orders', routePath: '/admin/draft-po-creator', section: 'PROCUREMENT & PLANNING', icon: 'procurement',
    required: ['purchase_order_creation.read'], actions: ['purchase_order_creation.create', 'purchase_order_creation.update', 'supplier_management.read'] },
  { name: 'PO Recommendation', routePath: '/admin/po-recommendations', section: 'PROCUREMENT & PLANNING', icon: 'po',
    required: ['restock_recommendation.read'], actions: ['restock_quantity.read', 'restock_approval.approve'] },
  { name: 'PO Receiving', routePath: '/admin/receivings', section: 'PROCUREMENT & PLANNING', icon: 'receiving',
    required: ['delivery_receiving.read'], actions: ['inventory_receiving.create'] },
  { name: 'Supplier Network', routePath: '/admin/suppliers', section: 'PROCUREMENT & PLANNING', icon: 'supplier',
    required: ['supplier_management.read'], actions: ['supplier_management.create', 'supplier_management.update', 'item_supplier_mapping.update'] },
  { name: 'Item Master', routePath: '/admin/items', section: 'PROCUREMENT & PLANNING', icon: 'items',
    required: ['item_master_management.read'], actions: ['item_master_management.create', 'item_master_management.update', 'item_master_management.archive', 'barcode_assignment.create'] },
  { name: 'Categories and Brands', routePath: '/admin/categories', section: 'PROCUREMENT & PLANNING', icon: 'category',
    required: ['category_management.read'], actions: ['category_management.create', 'category_management.update', 'brand_management.read', 'brand_management.create', 'brand_management.update'] },
  // INVENTORY INTELLIGENCE
  { name: 'Inventory Monitoring', routePath: '/admin/inventory', section: 'INVENTORY INTELLIGENCE', icon: 'inventory',
    required: ['centralized_inventory.read'], actions: ['automated_inventory_monitoring.read', 'location_based_inventory.read'] },
  { name: 'Transfer Requests', routePath: '/admin/transfer-requests', section: 'INVENTORY INTELLIGENCE', icon: 'transfer',
    required: ['inventory_transfers.read'], actions: ['inventory_transfers.create'] },
  { name: 'Stock Transfers', routePath: '/admin/transfers', section: 'INVENTORY INTELLIGENCE', icon: 'transfer',
    required: ['inventory_transfers.read'], actions: ['inventory_transfers.create', 'inventory_transfers.approve'] },
  { name: 'Interbranch Transfers', routePath: '/admin/ist', section: 'INVENTORY INTELLIGENCE', icon: 'transfer',
    required: ['inventory_transfers.read'], actions: ['inventory_transfers.create', 'inventory_transfers.update', 'inventory_transfers.approve'] },
  { name: 'Adjustments', routePath: '/admin/adjustments', section: 'INVENTORY INTELLIGENCE', icon: 'inventoryop',
    required: ['inventory_adjustments.read'], actions: ['inventory_adjustments.create', 'inventory_adjustments.approve'] },
  { name: 'Issuances', routePath: '/admin/issuances', section: 'INVENTORY INTELLIGENCE', icon: 'issuance-scan',
    required: ['inventory_issuance.read'], actions: ['inventory_issuance.create'] },
  // SALES & LOGISTICS
  { name: 'POS Operations', routePath: '/admin/transactions', section: 'SALES & LOGISTICS', icon: 'transactions',
    required: ['pos_sales_recording.read'], actions: ['pos_sales_recording.create', 'barcode_scanning_sales.create', 'sales_validation.create'] },
  { name: 'Purchase Orders', routePath: '/admin/purchase-orders', section: 'SALES & LOGISTICS', icon: 'purchaseorders',
    required: ['po_status_tracking.read'], actions: ['purchase_order_approval.approve'] },
  { name: 'Customer Registry', routePath: '/admin/customers', section: 'SALES & LOGISTICS', icon: 'customers',
    required: ['customer_info_capture.read'], actions: ['customer_info_capture.create', 'customer_info_capture.update'] },
  { name: 'Scan Barcode', routePath: '/admin/inventory-operation', section: 'SALES & LOGISTICS', icon: 'scan',
    required: ['barcode_inventory_processing.create'], actions: [] },
  { name: 'Issuance Scan', routePath: '/admin/issuance-scan', section: 'SALES & LOGISTICS', icon: 'issuance-scan',
    required: ['inventory_issuance.create'], actions: [] },
  { name: 'Delivery Receipts', routePath: '/admin/delivery-receipts', section: 'SALES & LOGISTICS', icon: 'reports',
    required: ['delivery_receipt_generation.read'], actions: ['delivery_receipt_generation.create'] },
  // COMPLIANCE & SUPPORT
  { name: 'Compliance Review', routePath: '/admin/compliance', section: 'COMPLIANCE & SUPPORT', icon: 'compliance',
    required: ['exception_detection.read'], actions: [] },
  { name: 'System Reports', routePath: '/admin/reports', section: 'COMPLIANCE & SUPPORT', icon: 'reports',
    required: ['inventory_reports.read'], actions: ['transaction_reports.read', 'audit_reports.read', 'export_reports.create'] },
  { name: 'Audit Trail', routePath: '/admin/audit', section: 'COMPLIANCE & SUPPORT', icon: 'audit',
    required: ['audit_trail_viewing.read'], actions: ['transaction_logs_audit.read', 'login_logs.read'] },
  { name: 'Alerts & Notices', routePath: '/admin/alerts', section: 'COMPLIANCE & SUPPORT', icon: 'notifications',
    required: ['notification_panel.read'], actions: [] },
];

const granted = (perms, key) => perms != null && perms[key] === true;

/** A bundle is visible when ALL required permissions are granted. */
export function bundleVisible(bundle, perms) {
  if (!bundle || !bundle.required || bundle.required.length === 0) return false;
  return bundle.required.every((k) => granted(perms, k));
}

/** 'on' = all required granted; 'partial' = some; 'off' = none. */
export function bundleState(bundle, perms) {
  const reqs = bundle.required || [];
  const have = reqs.filter((k) => granted(perms, k)).length;
  if (have === 0) return 'off';
  if (have === reqs.length) return 'on';
  return 'partial';
}
