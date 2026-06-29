import { channelFor, badgeIncreased, REALTIME_EVENTS } from './realtimeChannel';

describe('channelFor', () => {
  test.each([1, 2, 5])('centralized role %i → org', (roleId) => {
    expect(channelFor({ role: { role_id: roleId }, location_id: 9 })).toBe('org');
  });

  test('scoped branch_manager (3) → location channel from enforced_location_id', () => {
    expect(channelFor({ role: { role_id: 3 }, enforced_location_id: 7 })).toBe('location.7');
  });

  test('scoped warehouse_personnel (4) → location channel from location_id', () => {
    expect(channelFor({ role: { role_id: 4 }, location_id: 4 })).toBe('location.4');
  });

  test('scoped user without a location → null', () => {
    expect(channelFor({ role: { role_id: 4 } })).toBeNull();
  });

  test('no user → null', () => {
    expect(channelFor(null)).toBeNull();
  });
});

describe('badgeIncreased', () => {
  test('higher than previous → true', () => {
    expect(badgeIncreased(2, 5)).toBe(true);
  });
  test('equal → false', () => {
    expect(badgeIncreased(5, 5)).toBe(false);
  });
  test('lower (marked read) → false', () => {
    expect(badgeIncreased(5, 1)).toBe(false);
  });
  test('first load from 0 → true when positive', () => {
    expect(badgeIncreased(0, 3)).toBe(true);
  });
});

describe('REALTIME_EVENTS', () => {
  test('lists the four dotted event names', () => {
    expect(REALTIME_EVENTS).toEqual([
      '.approval-queue.changed',
      '.purchase-order.changed',
      '.inventory.changed',
      '.transfer.changed',
    ]);
  });
});
