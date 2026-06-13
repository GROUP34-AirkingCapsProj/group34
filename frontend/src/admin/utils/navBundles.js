/** Frontend mirror of NavBundleSeeder. Keep in sync. A page is visible iff ALL `required` perms are granted. */
export const NAV_BUNDLES = [
  { name: 'Approval Queue',        routePath: '/admin/approval-queue',   required: ['purchase_order_creation.read'] },
  { name: 'User Management',       routePath: '/admin/users',            required: ['user_account_management.read'] },
  { name: 'Location Management',   routePath: '/admin/branches',         required: ['location_based_inventory.read'] },
  { name: 'Archive Management',    routePath: '/admin/archive',          required: ['archive_viewing.read'] },
  { name: 'Purchase Requests',     routePath: '/admin/purchase-requests',required: ['purchase_request_creation.read'] },
  { name: 'Purchase Orders',       routePath: '/admin/draft-po-creator', required: ['purchase_order_creation.read'] },
  { name: 'PO Receiving',          routePath: '/admin/receivings',       required: ['delivery_receiving.read'] },
  { name: 'Supplier Network',      routePath: '/admin/suppliers',        required: ['supplier_management.read'] },
  { name: 'Item Master',           routePath: '/admin/items',            required: ['item_master_management.read'] },
  { name: 'Categories and Brands', routePath: '/admin/categories',       required: ['category_management.read'] },
  { name: 'Transfer Requests',     routePath: '/admin/transfer-requests',required: ['inventory_transfers.read'] },
  { name: 'Interbranch Transfers', routePath: '/admin/ist',              required: ['inventory_transfers.read'] },
  { name: 'Transactions',          routePath: '/admin/transactions',     required: ['pos_sales_recording.read'] },
  { name: 'Scan Barcode',          routePath: '/admin/inventory-operation', required: ['barcode_inventory_processing.create'] },
  { name: 'Compliance Review',     routePath: '/admin/compliance',       required: ['exception_detection.read'] },
  { name: 'Warranty Records',      routePath: '/admin/warranties',       required: ['warranty_records.read'] },
  { name: 'Warranty Claims',       routePath: '/admin/warranty-claims',  required: ['warranty_claims.read'] },
  { name: 'Audit Trail',           routePath: '/admin/audit',            required: ['audit_trail_viewing.read'] },
];

const granted = (perms, key) => perms != null && perms[key] === true;

export function bundleVisible(bundle, perms) {
  if (!bundle || !bundle.required || bundle.required.length === 0) return false;
  return bundle.required.every((k) => granted(perms, k));
}
