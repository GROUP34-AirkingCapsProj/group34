// Pure helpers for the realtime bell. Mirrors backend App\Support\ChannelAccess:
// all-location readers (admin=1, inventory_analyst=2, branch_manager=3,
// auditor=5) listen on `org`; the one read-scoped role, warehouse_personnel=4,
// listens on its own location.
//
// Keep this list in step with User::readsAllLocations() and not with the write
// scope — a channel delivers data to look at. The branch manager is here even
// though it still writes only at its own branch.
const READS_ALL_ROLE_IDS = [1, 2, 3, 5];

/** Event names broadcast by the backend (broadcastAs). Echo requires a leading
 *  dot to bind to custom event names. */
export const REALTIME_EVENTS = [
  '.approval-queue.changed',
  '.purchase-order.changed',
  '.inventory.changed',
  '.transfer.changed',
];

/**
 * Private channel name this user may subscribe to, or null if none.
 * All-location reader → 'org'. Read-scoped → 'location.{id}' (null if no
 * resolvable location).
 */
export function channelFor(user) {
  if (!user) return null;
  const roleId = Number(user?.role?.role_id ?? user?.role_id);
  if (READS_ALL_ROLE_IDS.includes(roleId)) return 'org';
  const locationId = user?.enforced_location_id ?? user?.location_id ?? null;
  if (locationId == null) return null;
  return `location.${locationId}`;
}

/** True when the new unread badge count is strictly greater than the previous. */
export function badgeIncreased(prev, next) {
  return Number(next) > Number(prev);
}
