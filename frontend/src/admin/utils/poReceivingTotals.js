// Pure helpers for scoping a purchase order's totals to a single destination
// warehouse. A PO carries one supplier but may have lines for several warehouses;
// a receiver at one warehouse should only see the money/items for their own lines.
// Kept dependency-free so it is unit-testable without importing the receiving page.

/** Money for one PO line: explicit subtotal, else quantity * unit price, else 0. */
export const lineMoney = (d) => {
  const qty = Number(d?.quantity_ordered ?? d?.quantity ?? d?.quantity_amount ?? 0);
  const price = Number(d?.unit_price ?? 0);
  return Number(d?.subtotal ?? qty * price) || 0;
};

/**
 * Lines on a PO destined for a given warehouse. Matches each line's `location_id`,
 * falling back to the PO header `location_id` for legacy single-warehouse POs whose
 * lines predate per-line warehouses. Pass `locationId` null/empty for centralized /
 * admin users who are not locked to one warehouse — they get every line.
 */
export const linesForLocation = (po, locationId) => {
  const details = Array.isArray(po?.details) ? po.details : [];
  if (!locationId) return details;
  return details.filter(
    (d) => String(d.location_id ?? po?.location_id) === String(locationId),
  );
};

/** Sum of line money for a PO's lines destined for `locationId` (all lines if null). */
export const locationAmount = (po, locationId) =>
  linesForLocation(po, locationId).reduce((s, d) => s + lineMoney(d), 0);

/** Count of a PO's lines destined for `locationId` (all lines if null). */
export const locationItemCount = (po, locationId) =>
  linesForLocation(po, locationId).length;

/**
 * Per-warehouse breakdown of a PO: [{ locationId, locationName, amount, count }].
 * Used for the admin/centralized card view so distribution is visible at a glance.
 */
export const warehouseBreakdown = (po) => {
  const details = Array.isArray(po?.details) ? po.details : [];
  const map = new Map();
  details.forEach((d) => {
    const key = String(d.location_id ?? po?.location_id ?? '__none__');
    const name =
      d.location_name || d.location?.location_name || 'Unassigned warehouse';
    if (!map.has(key)) {
      map.set(key, { locationId: key, locationName: name, amount: 0, count: 0 });
    }
    const g = map.get(key);
    g.amount += lineMoney(d);
    g.count += 1;
  });
  return Array.from(map.values());
};
