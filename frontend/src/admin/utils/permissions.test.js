import { groupByFeatureGroup, FEATURE_GROUPS, ROLE_COLUMNS, keyOf } from './permissions';

test('keyOf builds feature.action key', () => {
  expect(keyOf('inventory_transfers', 'approve')).toBe('inventory_transfers.approve');
});

test('groupByFeatureGroup buckets known features and ignores unknown', () => {
  const perms = [
    { permission_id: 1, feature: 'inventory_transfers', action: 'approve' },
    { permission_id: 2, feature: 'made_up_feature', action: 'read' },
  ];
  const grouped = groupByFeatureGroup(perms);
  const transfersGroup = grouped.find((g) =>
    g.features.some((f) => f.feature === 'inventory_transfers'));
  expect(transfersGroup).toBeTruthy();
  const other = grouped.find((g) => g.label === 'Other');
  expect(other.features.some((f) => f.feature === 'made_up_feature')).toBe(true);
});

test('ROLE_COLUMNS lists the five roles with ids', () => {
  expect(ROLE_COLUMNS.map((r) => r.id)).toEqual([1, 2, 3, 4, 5]);
});
