/**
 * Shared helpers for the RBAC management pages.
 * Feature groups mirror the spec (docs/superpowers/specs/2026-06-11-rbac-permission-overrides-design.md §8).
 */

export const ROLE_COLUMNS = [
  { id: 1, slug: 'admin', label: 'SA' },
  { id: 2, slug: 'inventory_analyst', label: 'IA' },
  { id: 3, slug: 'branch_manager', label: 'BM' },
  { id: 4, slug: 'warehouse_personnel', label: 'WP' },
  { id: 5, slug: 'auditor', label: 'AU' },
];

export const ACTIONS = ['create', 'read', 'update', 'archive', 'approve'];

export const keyOf = (feature, action) => `${feature}.${action}`;

export const FEATURE_GROUPS = [
  { label: 'I. Core Inventory & Operations', features: ['centralized_inventory', 'automated_inventory_monitoring', 'location_based_inventory', 'barcode_inventory_processing', 'inventory_receiving', 'inventory_issuance', 'inventory_transfers', 'inventory_adjustments'] },
  { label: 'II. Item, Barcode & Classification', features: ['item_master_management', 'category_management', 'brand_management', 'uom_management', 'barcode_assignment'] },
  { label: 'III. User, Role & Security', features: ['user_account_management', 'role_assignment', 'rbac_enforcement'] },
  { label: 'IV. Dashboard & UI', features: ['role_dashboards', 'summary_cards', 'quick_actions', 'notification_panel'] },
  { label: 'V. Restocking & Low-Stock', features: ['reorder_monitoring', 'safety_stock', 'consumption_rate', 'low_stock_prediction', 'restock_recommendation', 'restock_quantity', 'restock_approval', 'restock_history'] },
  { label: 'VI. Supplier & Purchase Orders', features: ['supplier_management', 'item_supplier_mapping', 'purchase_order_creation', 'purchase_order_approval', 'po_status_tracking', 'delivery_receiving'] },
  { label: 'VII. Point of Sale', features: ['pos_sales_recording', 'barcode_scanning_sales', 'sales_validation', 'customer_info_capture', 'delivery_receipt_generation', 'pos_transaction_logs'] },
  { label: 'VIII. Transaction Control', features: ['transaction_validation', 'transaction_approval', 'transaction_rejection'] },
  { label: 'IX. Audit & Compliance', features: ['audit_trail_viewing', 'transaction_logs_audit', 'login_logs', 'exception_detection'] },
  { label: 'X. Analytics', features: ['stock_level_analysis', 'consumption_analysis', 'inventory_turnover_analysis'] },
  { label: 'XI. Reporting', features: ['inventory_reports', 'transaction_reports', 'audit_reports', 'export_reports'] },
  { label: 'XII. Document & Data Management', features: ['document_upload', 'document_verification', 'document_viewing', 'data_archiving', 'archive_viewing'] },
];

const prettyFeature = (f) => f.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/**
 * Turn a flat permissions catalog into grouped rows:
 * [{ label, features: [{ feature, title, actions: { create: permId, ... } }] }]
 * Unknown features collect into a trailing "Other" group rather than being dropped.
 */
export function groupByFeatureGroup(permissions) {
  const byFeature = {};
  permissions.forEach((p) => {
    byFeature[p.feature] = byFeature[p.feature] || { feature: p.feature, title: prettyFeature(p.feature), actions: {} };
    byFeature[p.feature].actions[p.action] = p.permission_id;
  });

  const known = new Set();
  const groups = FEATURE_GROUPS.map((g) => {
    const features = g.features.filter((f) => byFeature[f]).map((f) => {
      known.add(f);
      return byFeature[f];
    });
    return { label: g.label, features };
  }).filter((g) => g.features.length > 0);

  const others = Object.values(byFeature).filter((f) => !known.has(f.feature));
  if (others.length > 0) {
    groups.push({ label: 'Other', features: others });
  }
  return groups;
}
