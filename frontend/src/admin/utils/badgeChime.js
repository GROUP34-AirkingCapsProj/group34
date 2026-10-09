/**
 * Decides whether a new unread-notification count should ring the chime.
 *
 * Extracted from AdminLayout rather than left inline so the rule can be tested
 * without mounting the whole layout (which needs a router, an auth context and
 * the summary API). The three cases it encodes are each a bug that was easy to
 * ship:
 *
 *   - no baseline yet -> silent. The first summary response reports whatever was
 *     already waiting when the page opened. Ringing on it means every page load
 *     with unread notifications chimes.
 *   - count fell or held -> silent. The same poll runs on
 *     `approval-notifications-updated`, which the app dispatches when the user
 *     reads or acts on a notification; that lowers the count and is not news.
 *   - count rose -> ring.
 *
 * @param {number} previous   last known badge count
 * @param {number} next       count just received
 * @param {boolean} hasBaseline whether `previous` is a real reading
 * @returns {boolean}
 */
export function shouldChimeForBadge(previous, next, hasBaseline) {
  if (!hasBaseline) return false;

  const before = Number(previous);
  const after = Number(next);

  // A non-numeric reading means the summary shape changed or the request half
  // failed; treat it as no news rather than ringing on NaN comparisons.
  if (!Number.isFinite(before) || !Number.isFinite(after)) return false;

  return after > before;
}

// A PO approval can replace a pending alert without increasing the badge.
export function hasNewWarehouseNotification(previousIds, nextIds, hasBaseline) {
  if (!hasBaseline || !Array.isArray(nextIds)) return false;
  const previous = new Set(previousIds || []);
  return nextIds.some((id) => !previous.has(id));
}
