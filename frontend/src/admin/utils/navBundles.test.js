import { NAV_BUNDLES, bundleVisible, bundleState } from './navBundles';

test('NAV_BUNDLES contains the Item Master bundle with a read gate', () => {
  const items = NAV_BUNDLES.find((b) => b.routePath === '/admin/items');
  expect(items).toBeTruthy();
  expect(items.required).toContain('item_master_management.read');
});

test('bundleVisible true only when all required perms granted', () => {
  const perms = { 'item_master_management.read': true };
  const items = NAV_BUNDLES.find((b) => b.routePath === '/admin/items');
  expect(bundleVisible(items, perms)).toBe(true);
  expect(bundleVisible(items, {})).toBe(false);
});

test('bundleState reports on/partial/off', () => {
  const reqTwo = { routePath: '/x', required: ['a.read', 'b.read'], actions: [] };
  expect(bundleState(reqTwo, { 'a.read': true, 'b.read': true })).toBe('on');
  expect(bundleState(reqTwo, { 'a.read': true })).toBe('partial');
  expect(bundleState(reqTwo, {})).toBe('off');
});
