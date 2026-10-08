import { NAV_BUNDLES } from './navBundles';
import { allFeatures } from './permissions';
import {
  EXTRA_FEATURES_BY_ROUTE,
  ORPHAN_FEATURES,
  configureFeatures,
  requiredFeatureOf,
} from './navConfigureMap';

// Reachable = every feature that is a page's required feature, an assigned extra,
// or an explicit orphan. NAV_BUNDLES mirrors the seeder's route→required feature.
const reachableFeatures = () => {
  const set = new Set();
  NAV_BUNDLES.forEach((b) => {
    set.add(b.required[0].split('.')[0]);
    (EXTRA_FEATURES_BY_ROUTE[b.routePath] || []).forEach((f) => set.add(f));
  });
  ORPHAN_FEATURES.forEach((f) => set.add(f));
  return set;
};

describe('navConfigureMap coverage', () => {
  test('every catalog feature is reachable from some nav page or the Other row', () => {
    const reachable = reachableFeatures();
    const missing = allFeatures().filter((f) => !reachable.has(f));
    expect(missing).toEqual([]);
  });

  test('no extra feature is also listed as an orphan', () => {
    const extras = Object.values(EXTRA_FEATURES_BY_ROUTE).flat();
    const overlap = extras.filter((f) => ORPHAN_FEATURES.includes(f));
    expect(overlap).toEqual([]);
  });

  test('an extra feature is never also a page required feature (no accidental double-home)', () => {
    const requiredFeatures = new Set(NAV_BUNDLES.map((b) => b.required[0].split('.')[0]));
    const extras = Object.values(EXTRA_FEATURES_BY_ROUTE).flat();
    const clash = extras.filter((f) => requiredFeatures.has(f));
    expect(clash).toEqual([]);
  });
});

describe('configureFeatures', () => {
  const bundle = {
    route_path: '/admin/items',
    permissions: [
      { feature: 'item_master_management', action: 'read', is_required: true },
      { feature: 'item_master_management', action: 'create', is_required: false },
    ],
  };

  test('lists the required feature first, then extras', () => {
    expect(configureFeatures(bundle)).toEqual(['item_master_management', 'barcode_assignment']);
  });

  test('de-duplicates if an extra repeats the required feature', () => {
    const b = { route_path: '/admin/x', permissions: [{ feature: 'foo', is_required: true }] };
    expect(configureFeatures(b)).toEqual(['foo']);
  });

  test('requiredFeatureOf falls back to first permission when none flagged required', () => {
    const b = { permissions: [{ feature: 'bar', action: 'read' }] };
    expect(requiredFeatureOf(b)).toBe('bar');
  });

  test('a page with no extras shows only its required feature', () => {
    const b = { route_path: '/admin/reports', permissions: [{ feature: 'report_viewing', is_required: true }] };
    expect(configureFeatures(b)).toEqual(['report_viewing']);
  });
});
