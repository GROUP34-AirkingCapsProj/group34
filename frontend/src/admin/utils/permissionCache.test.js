import { shouldRefreshPermissions } from './permissionCache';

describe('shouldRefreshPermissions', () => {
  const TTL = 60000;

  it('refreshes when never fetched', () => {
    expect(shouldRefreshPermissions(null, 1_000_000, TTL)).toBe(true);
  });

  it('does not refresh within the TTL window', () => {
    expect(shouldRefreshPermissions(1_000_000, 1_030_000, TTL)).toBe(false);
  });

  it('refreshes once the TTL has elapsed', () => {
    expect(shouldRefreshPermissions(1_000_000, 1_061_000, TTL)).toBe(true);
  });

  it('refreshes exactly at the TTL boundary', () => {
    expect(shouldRefreshPermissions(1_000_000, 1_060_000, TTL)).toBe(true);
  });
});
