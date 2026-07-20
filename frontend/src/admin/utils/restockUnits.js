// pieces → packages for installation materials; passthrough otherwise.
// PR/PO quantity means PACKAGES for materials, so recommendation piece counts
// must be converted before landing in a qty field (ceil = safe over-order).
export const toOrderQty = (pieces, prod) => {
  const ppp = Number(prod?.piecesPerPackage) || 0;
  if (prod?.type === 'installation_material' && ppp > 1) {
    return Math.ceil(pieces / ppp);
  }
  return pieces;
};
