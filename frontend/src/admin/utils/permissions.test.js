import { actionsForFeature, pageAccessStatus } from './permissions';

const catalog = [
  { permission_id: 10, feature: 'item_master_management', action: 'create' },
  { permission_id: 11, feature: 'item_master_management', action: 'read' },
  { permission_id: 12, feature: 'item_master_management', action: 'update' },
  { permission_id: 13, feature: 'item_master_management', action: 'archive' },
  { permission_id: 14, feature: 'item_master_management', action: 'approve' },
  { permission_id: 20, feature: 'barcode_inventory_processing', action: 'create' },
];

test('actionsForFeature returns the feature actions in read-first display order', () => {
  expect(actionsForFeature(catalog, 'item_master_management')).toEqual([
    { action: 'read', permission_id: 11 },
    { action: 'create', permission_id: 10 },
    { action: 'update', permission_id: 12 },
    { action: 'archive', permission_id: 13 },
    { action: 'approve', permission_id: 14 },
  ]);
});

test('actionsForFeature handles a single-action feature', () => {
  expect(actionsForFeature(catalog, 'barcode_inventory_processing')).toEqual([
    { action: 'create', permission_id: 20 },
  ]);
});

test('actionsForFeature returns [] for unknown feature or empty catalog', () => {
  expect(actionsForFeature(catalog, 'nope')).toEqual([]);
  expect(actionsForFeature(null, 'item_master_management')).toEqual([]);
});

describe('pageAccessStatus', () => {
  const acts = actionsForFeature(catalog, 'item_master_management'); // read + 4 writes

  test("'none' when the access (read) action is not effective", () => {
    expect(pageAccessStatus(acts, { read: false, create: true })).toBe('none');
  });
  test("'view' when only read is effective", () => {
    expect(pageAccessStatus(acts, { read: true })).toBe('view');
  });
  test("'partial' when read + some writes are effective", () => {
    expect(pageAccessStatus(acts, { read: true, create: true })).toBe('partial');
  });
  test("'full' when read + all writes are effective", () => {
    expect(pageAccessStatus(acts, { read: true, create: true, update: true, archive: true, approve: true })).toBe('full');
  });
  test("single create-only feature: 'view' when its sole action is effective, else 'none'", () => {
    const one = actionsForFeature(catalog, 'barcode_inventory_processing');
    expect(pageAccessStatus(one, { create: true })).toBe('view');
    expect(pageAccessStatus(one, { create: false })).toBe('none');
  });
});
