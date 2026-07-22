// Helpers for expressing a piece count in terms of a consumable's package size.
// A consumable may define `pieces_per_package` (e.g. 10) and `package_unit` (e.g. "Box").
// Inventory, transfers, dispatch and receive all work in PIECES; these helpers only
// re-express a piece total for display/entry — no rounding of the stored value.

/** Split a piece count into whole packages + loose pieces. ppp <= 1 → all loose. */
export const piecesToPackages = (pieces, ppp) => {
  const p = Math.max(0, Number(pieces) || 0);
  const n = Number(ppp) || 0;
  if (n <= 1) return { boxes: 0, loose: p };
  return { boxes: Math.floor(p / n), loose: p % n };
};

/** "3 Box + 5 pcs", or null when the product has no package size. */
export const packageBreakdown = (pieces, ppp, unit = 'pkg') => {
  const n = Number(ppp) || 0;
  if (n <= 1) return null;
  const { boxes, loose } = piecesToPackages(pieces, n);
  const parts = [];
  if (boxes > 0) parts.push(`${boxes} ${unit || 'pkg'}`);
  if (loose > 0) parts.push(`${loose} pcs`);
  return parts.length ? parts.join(' + ') : `0 ${unit || 'pkg'}`;
};

/** "35 pcs · 3 Box + 5 pcs", or just "35 pcs" when there's no package size. */
export const formatPieces = (pieces, ppp, unit) => {
  const p = Math.max(0, Number(pieces) || 0);
  const bd = packageBreakdown(p, ppp, unit);
  return bd ? `${p} pcs · ${bd}` : `${p} pcs`;
};
