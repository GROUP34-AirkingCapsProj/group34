import { channelFor, badgeIncreased, REALTIME_EVENTS } from './realtimeChannel';

describe('channelFor', () => {
  // Mirrors User::readsAllLocations() on the backend, which is what
  // ChannelAccess::canAccessOrg() asks. branch_manager (3) is in this group: it
  // reads every location, even though it still writes only at its own.
  test.each([1, 2, 3, 5])('all-location reader %i → org', (roleId) => {
    expect(channelFor({ role: { role_id: roleId }, location_id: 9 })).toBe('org');
  });

  test('branch_manager (3) → org even with an enforced location', () => {
    expect(channelFor({ role: { role_id: 3 }, enforced_location_id: 7 })).toBe('org');
  });

  test('read-scoped warehouse_personnel (4) → location channel from location_id', () => {
    expect(channelFor({ role: { role_id: 4 }, location_id: 4 })).toBe('location.4');
  });

  test('read-scoped warehouse_personnel (4) → location channel from enforced_location_id', () => {
    expect(channelFor({ role: { role_id: 4 }, enforced_location_id: 7 })).toBe('location.7');
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
