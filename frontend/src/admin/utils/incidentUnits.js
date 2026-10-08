import { totalPiecesFrom } from './incidentFlow';

/**
 * A unit row on the incident document, and the pack arithmetic that turns what
 * was typed into the PIECES the API stores.
 *
 * Shared between the Units section, which renders the rows, and the document
 * shell, which decides whether the report can be filed and assembles the
 * payload. Two copies of "how many pieces is this row" is exactly the kind of
 * divergence that once wrote off a single piece for a damaged box.
 */

let nextUid = 1;

/** A blank unit. `single` is a whole appliance; `indoor`/`outdoor` are halves. */
export const newUnit = (unitRole = 'single') => ({
  uid: `unit-${nextUid++}`,
  product_id: '',
  batch_id: '',
  // Bound by a scan when there is one to bind: a serial FK is an id, not an
  // answer the reporter types.
  serial_id: null,
  unit_role: unitRole,
  model_code: '',
  serial_number: '',
  packs: 0,
  loose_pieces: 0,
  quantity: 1,
  // The wrong/correct pair, asked only on the matching return reason.
  expected_product_id: '',
  expected_quantity: '',
});

/** The pack size and package noun for a product, or the no-packs defaults. */
export const packingOf = (product) => {
  const per = Number(product?.pieces_per_package) || 0;
  return {
    piecesPerPackage: per,
    usesPacks: per > 1,
    packageUnit: product?.installation_material_detail?.package_unit || product?.package_unit || 'pkg',
  };
};

/**
 * How many PIECES this row is about.
 *
 * The product is passed in rather than looked up here, because a second unit is
 * very often a different product from the first — reusing the first row's
 * pieces_per_package is exactly how a pack quantity turns into a single piece.
 */
export const piecesOnRow = (row, product) => totalPiecesFrom({
  packs: row.packs,
  loosePieces: row.loose_pieces,
  quantity: row.quantity,
  piecesPerPackage: packingOf(product).piecesPerPackage,
});

/** Every row with its resolved piece count, for the readiness check and payload. */
export const resolveUnits = (units, products) => units.map((row) => {
  const product = products.find((p) => String(p.product_id) === String(row.product_id));
  return { ...row, pieces: piecesOnRow(row, product) };
});
