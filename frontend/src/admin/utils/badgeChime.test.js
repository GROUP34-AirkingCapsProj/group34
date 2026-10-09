import { shouldChimeForBadge, hasNewWarehouseNotification } from './badgeChime';

test('detects approval and incoming transfer alerts even when the total badge stays unchanged', () => {
  expect(hasNewWarehouseNotification([], ['po_receive:1'], true)).toBe(true);
  expect(hasNewWarehouseNotification(['transfer_dispatch:2'], ['transfer_receive:2'], true)).toBe(true);
  expect(hasNewWarehouseNotification(['po_receive:1'], ['po_receive:1'], true)).toBe(false);
  expect(hasNewWarehouseNotification(['po_receive:1'], [], true)).toBe(false);
  expect(hasNewWarehouseNotification([], ['po_receive:1'], false)).toBe(false);
});

describe('shouldChimeForBadge', () => {
  it('stays silent on the first reading, however many are waiting', () => {
    // The regression this guards: ringing here means every page load with
    // unread notifications chimes at the user.
    expect(shouldChimeForBadge(0, 7, false)).toBe(false);
  });

  it('rings when the count rises', () => {
    expect(shouldChimeForBadge(2, 3, true)).toBe(true);
  });

  it('rings once for a burst that arrives together', () => {
    expect(shouldChimeForBadge(0, 5, true)).toBe(true);
  });

  it('stays silent when the count is unchanged', () => {
    // The 60s poll re-reports the same total every minute; this is the case
    // that would otherwise ring forever.
    expect(shouldChimeForBadge(4, 4, true)).toBe(false);
  });

  it('stays silent when the count falls', () => {
    // Dispatched by `approval-notifications-updated` after the user reads one.
    expect(shouldChimeForBadge(4, 1, true)).toBe(false);
  });

  it('stays silent when the count falls to zero', () => {
    expect(shouldChimeForBadge(3, 0, true)).toBe(false);
  });

  it('treats a missing or malformed count as no news', () => {
    expect(shouldChimeForBadge(1, undefined, true)).toBe(false);
    expect(shouldChimeForBadge(1, null, true)).toBe(false);
    expect(shouldChimeForBadge(undefined, 5, true)).toBe(false);
    expect(shouldChimeForBadge(1, 'abc', true)).toBe(false);
  });

  it('accepts numeric strings, which is what JSON sometimes yields', () => {
    expect(shouldChimeForBadge('1', '2', true)).toBe(true);
    expect(shouldChimeForBadge('2', '2', true)).toBe(false);
  });
});
