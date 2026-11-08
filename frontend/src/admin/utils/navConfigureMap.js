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
  '/config':              ['user_account_management'],
  '/draft-po-creator':    ['purchase_order_verification'],
  '/archive':             ['data_archiving'],
  '/purchase-requests':   ['purchase_request_approval', 'po_recommendations'],
  '/suppliers':           ['item_supplier_mapping'],
  '/items':               ['barcode_assignment'],
  '/categories':          ['category_management', 'brand_management'],
  '/transactions':        ['delivery_receipt_generation'],
  '/inventory-operation': ['barcode_inventory_processing', 'inventory_issuance', 'inventory_adjustments'],
  '/warranties':          ['warranty_claims'],
};

/**
 * Features that belong to no navigation page at all. Surfaced in a synthetic
 * "Other / No page" row so they stay reachable. Currently every feature has a
 * home (page required-feature or an extra above), so this is empty.
 */
export const ORPHAN_FEATURES = [];

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
