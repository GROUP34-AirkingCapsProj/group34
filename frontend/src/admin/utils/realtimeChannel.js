// Pure helpers for the realtime bell. Mirrors backend App\Support\ChannelAccess:
// centralized roles (admin=1, inventory_analyst=2, auditor=5) listen on `org`;
// scoped roles (branch_manager=3, warehouse_personnel=4) listen on their location.

const CENTRALIZED_ROLE_IDS = [1, 2, 5];

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
 * Centralized → 'org'. Scoped → 'location.{id}' (null if no resolvable location).
 */
export function channelFor(user) {
  if (!user) return null;
  const roleId = Number(user?.role?.role_id ?? user?.role_id);
  if (CENTRALIZED_ROLE_IDS.includes(roleId)) return 'org';
  const locationId = user?.enforced_location_id ?? user?.location_id ?? null;
  if (locationId == null) return null;
  return `location.${locationId}`;
}

/** True when the new unread badge count is strictly greater than the previous. */
export function badgeIncreased(prev, next) {
  return Number(next) > Number(prev);
}
