export const ROLE_COLUMNS = [
  { id: 1, slug: 'admin', label: 'SA', name: 'System Administrator', accent: '#dc2626' },
  { id: 2, slug: 'inventory_analyst', label: 'IA', name: 'Inventory Analyst', accent: '#7c3aed' },
  { id: 3, slug: 'branch_manager', label: 'BM', name: 'Branch Manager', accent: '#059669' },
  { id: 4, slug: 'warehouse_personnel', label: 'WP', name: 'Warehouse Personnel', accent: '#2563eb' },
  { id: 5, slug: 'auditor', label: 'AU', name: 'Auditor', accent: '#d97706' },
];

export const ACTIONS = ['create', 'read', 'update', 'archive', 'approve', 'verify'];

export const ACTION_META = {
  create:  { label: 'Create',  desc: 'Add new records.' },
  read:    { label: 'Read',    desc: 'View records and lists.' },
  update:  { label: 'Update',  desc: 'Edit existing records.' },
  archive: { label: 'Archive', desc: 'Soft-delete / move to archive.' },
  approve: { label: 'Approve', desc: 'Approve or finalize records.' },
  verify:  { label: 'Verify',  desc: 'Verify or reject records.' },
};

export const keyOf = (feature, action) => `${feature}.${action}`;

// Groups cover the real features that exist in the catalog.
export const FEATURE_GROUPS = [
  { label: 'I. Users & Security',               features: ['user_account_management', 'role_assignment', 'permission_audit', 'system_configuration'] },
  { label: 'II. Item & Classification',          features: ['item_master_management', 'category_management', 'brand_management', 'barcode_assignment'] },
  { label: 'III. Suppliers & Procurement',       features: ['supplier_management', 'item_supplier_mapping', 'purchase_order_creation', 'purchase_order_verification', 'purchase_request_creation', 'purchase_request_approval', 'po_recommendations'] },
  { label: 'IV. Inventory Operations',           features: ['barcode_inventory_processing', 'delivery_receiving', 'inventory_issuance', 'inventory_transfers', 'stock_transfers', 'inventory_adjustments', 'inventory_monitoring'] },
  { label: 'V. Sales & Delivery',                features: ['sales_recording', 'delivery_receipt_generation', 'customer_management'] },
  { label: 'VI. Location',                       features: ['location_based_inventory'] },
  { label: 'VII. Warranty & After-Sales',        features: ['warranty_records', 'warranty_claims'] },
  { label: 'VIII. Audit, Compliance & Archive',  features: ['audit_trail_viewing', 'exception_detection', 'archive_viewing', 'data_archiving'] },
  { label: 'IX. Dashboards, Reporting & Notifications', features: ['dashboard_access', 'analytics_dashboard', 'report_viewing', 'notification_panel'] },
];

const prettyFeature = (f) =>
  f.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/** Human-readable title for a feature key (e.g. "item_master_management" → "Item Master Management"). */
export const featureTitle = prettyFeature;

/** Every feature key known to the app, flattened from FEATURE_GROUPS. */
export function allFeatures() {
  return FEATURE_GROUPS.flatMap((g) => g.features);
}

/**
 * Group a flat catalog into
 * [{label, features:[{feature, title, actions:{action: permission_id}}]}]
 * Unknown features collect into "Other".
 */
export function groupByFeatureGroup(permissions) {
  const byFeature = {};
  permissions.forEach((p) => {
    byFeature[p.feature] = byFeature[p.feature] || {
      feature: p.feature,
      title: prettyFeature(p.feature),
      actions: {},
    };
    byFeature[p.feature].actions[p.action] = p.permission_id;
  });

  const known = new Set();
  const groups = FEATURE_GROUPS.map((g) => {
    const features = g.features
      .filter((f) => byFeature[f])
      .map((f) => { known.add(f); return byFeature[f]; });
    return { label: g.label, features };
  }).filter((g) => g.features.length > 0);

  const others = Object.values(byFeature).filter((f) => !known.has(f.feature));
  if (others.length) groups.push({ label: 'Other', features: others });

  return groups;
}

// Display order for action columns/rows (read first so "access" reads naturally).
const ACTION_DISPLAY_ORDER = ['read', 'create', 'update', 'archive', 'approve', 'verify'];

/**
 * The catalog actions that exist for a feature, as [{ action, permission_id }],
 * in read-first display order. Returns [] for unknown feature or missing catalog.
 */
export function actionsForFeature(catalog, feature) {
  const byAction = {};
  (catalog || []).forEach((p) => {
    if (p.feature === feature) byAction[p.action] = p.permission_id;
  });
  return ACTION_DISPLAY_ORDER
    .filter((a) => byAction[a] != null)
    .map((a) => ({ action: a, permission_id: byAction[a] }));
}

/**
 * Summarize a page's access from its primary feature's effective action map.
 * @param actions [{action, permission_id}] from actionsForFeature
 * @param effectiveByAction { [action]: boolean } — effective (pending-aware) grants
 * @returns 'none' | 'view' | 'partial' | 'full'
 */
export function pageAccessStatus(actions, effectiveByAction) {
  if (!actions || actions.length === 0) return 'none';
  const has = (a) => effectiveByAction?.[a] === true;
  // "access" gate = read if the feature has it, otherwise its first action (e.g. create).
  const accessAction = actions.some((x) => x.action === 'read') ? 'read' : actions[0].action;
  if (!has(accessAction)) return 'none';
  const writes = actions.filter((x) => x.action !== accessAction);
  if (writes.length === 0) return 'view';
  const granted = writes.filter((x) => has(x.action)).length;
  if (granted === 0) return 'view';
  if (granted === writes.length) return 'full';
  return 'partial';
}
