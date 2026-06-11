/**
 * Shared helpers for the RBAC management pages.
 * Feature groups mirror the spec (docs/superpowers/specs/2026-06-11-rbac-permission-overrides-design.md §8).
 */

// label = short code shown in column headers; name = full role; accent mirrors ROLE_META in roles.js.
export const ROLE_COLUMNS = [
  { id: 1, slug: 'admin', label: 'SA', name: 'System Administrator', accent: '#dc2626', blurb: 'Full system access and configuration.' },
  { id: 2, slug: 'inventory_analyst', label: 'IA', name: 'Inventory Analyst', accent: '#7c3aed', blurb: 'Item master, suppliers, analytics & reports.' },
  { id: 3, slug: 'branch_manager', label: 'BM', name: 'Branch Manager', accent: '#059669', blurb: 'Branch operations, POS, and approvals.' },
  { id: 4, slug: 'warehouse_personnel', label: 'WP', name: 'Warehouse Personnel', accent: '#2563eb', blurb: 'Receiving, issuance, transfers, scanning.' },
  { id: 5, slug: 'auditor', label: 'AU', name: 'Auditor', accent: '#d97706', blurb: 'Read-only audit & compliance access.' },
];

export const ACTIONS = ['create', 'read', 'update', 'archive', 'approve'];

// Human-readable meaning of each action, used by the on-page legend.
export const ACTION_META = {
  create: { label: 'Create', desc: 'Add new records.' },
  read: { label: 'Read', desc: 'View records and lists.' },
  update: { label: 'Update', desc: 'Edit existing records.' },
  archive: { label: 'Archive', desc: 'Soft-delete / move records to the archive.' },
  approve: { label: 'Approve', desc: 'Approve or finalize records.' },
};

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
