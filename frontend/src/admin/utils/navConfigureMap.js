/**
 * Which navigation page is the "home" for each permission feature.
 *
 * A nav bundle's Configure modal always shows its own required feature. This map
 * adds the EXTRA features (beyond the required one) that logically belong to the
 * same page, so every catalog permission is reachable from the Navigation Access
 * list without a separate full-matrix tab.
 *
 * Keyed by the bundle's route_path (stable across renames). Keep in sync with the
 * permission catalog + NavBundleSeeder.
 */
export const EXTRA_FEATURES_BY_ROUTE = {
  '/admin/archive':             ['data_archiving'],
  '/admin/purchase-requests':   ['purchase_request_approval'],
  '/admin/suppliers':           ['item_supplier_mapping'],
  '/admin/items':               ['barcode_assignment'],
  '/admin/categories':          ['brand_management'],
  '/admin/transactions':        ['delivery_receipt_generation'],
  '/admin/inventory-operation': ['delivery_receiving', 'inventory_issuance', 'inventory_adjustments'],
  '/admin/warranties':          ['warranty_claims'],
};

/**
 * Features that belong to no navigation page (e.g. user/role management lives in
 * admin-only System Configuration). Surfaced in a synthetic "Other / No page" row
 * so they stay reachable.
 */
export const ORPHAN_FEATURES = ['user_account_management', 'role_assignment'];

/** Display order for sidebar-section headers in the Navigation Access list. */
export const SECTION_ORDER = [
  'ADMINISTRATION',
  'PROCUREMENT & PLANNING',
  'INVENTORY INTELLIGENCE',
  'SALES & LOGISTICS',
  'COMPLIANCE & SUPPORT',
];

/** Label + route for the synthetic "no navigation page" Configure row. */
export const OTHER_HOME = {
  name: 'Other permissions',
  sidebar_section: 'NO NAVIGATION PAGE',
  route_path: '__other__',
};

/** The required feature of a bundle (the one that gates the page's visibility). */
export function requiredFeatureOf(bundle) {
  const perms = bundle?.permissions || [];
  const req = perms.find((p) => p.is_required) || perms[0];
  return req?.feature || null;
}

/**
 * Ordered, de-duplicated list of features whose actions the bundle's Configure
 * modal should show: its required feature first, then any assigned extras.
 */
export function configureFeatures(bundle) {
  const required = requiredFeatureOf(bundle);
  const extras = EXTRA_FEATURES_BY_ROUTE[bundle?.route_path] || [];
  const seen = new Set();
  const out = [];
  [required, ...extras].forEach((f) => {
    if (f && !seen.has(f)) { seen.add(f); out.push(f); }
  });
  return out;
}
