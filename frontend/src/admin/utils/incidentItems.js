/**
 * The vocabulary of a physical unit on an incident report.
 *
 * Mirrors App\Models\IncidentItem::ROLES. A report can carry several units — a
 * split-system aircon files as an indoor unit and an outdoor unit on one
 * report, each with its own model code, serial and inspection outcome.
 */

export const UNIT_ROLES = [
  { value: 'single', label: 'Single unit' },
  { value: 'indoor', label: 'Indoor unit' },
  { value: 'outdoor', label: 'Outdoor unit' },
];

export const unitRoleLabel = (value) =>
  UNIT_ROLES.find((r) => r.value === value)?.label || value;

/**
 * What the inspector can decide about one unit. Mirrors
 * App\Models\IncidentItem::OUTCOMES.
 *
 * `restock` is the only one that keeps the goods: everything else means they
 * left the building or were destroyed, so confirming it reduces the stock
 * count. The `keepsStock` flag is stated here once so no screen has to
 * re-derive which outcomes are destructive — getting that backwards would
 * mislabel a write-off as harmless.
 */
export const ITEM_OUTCOMES = [
  {
    value: 'restock',
    label: 'Put back into stock',
    keepsStock: true,
    help: 'The unit is fine. It becomes pickable again; the count does not change, because filing never deducted it.',
  },
  {
    value: 'scrap',
    label: 'Scrap',
    keepsStock: false,
    help: 'Destroyed or unsellable. Confirming deducts it from the stock count.',
  },
  {
    value: 'return_to_supplier',
    label: 'Return to supplier',
    keepsStock: false,
    help: 'Goes back to the supplier. Confirming deducts it from the stock count.',
  },
  {
    value: 'write_off',
    label: 'Write off as lost',
    keepsStock: false,
    help: 'Gone and not coming back. Confirming deducts it and records the loss.',
  },
];

export const outcomeMeta = (value) => ITEM_OUTCOMES.find((o) => o.value === value) || null;

export const outcomeLabel = (value) =>
  outcomeMeta(value)?.label || (value ? String(value).replace(/_/g, ' ') : '—');

/**
 * Where a unit currently stands. Mirrors App\Models\IncidentItem::STATUSES.
 *
 * `quarantined` is the one that surprises people: the goods are physically
 * present and still counted in quantity_on_hand — they are simply unpickable
 * until someone decides. Nothing has been deducted.
 */
export const ITEM_STATUSES = [
  { value: 'quarantined', label: 'Quarantined', badge: 'ak-badge--amber' },
  { value: 'available',   label: 'Back in stock', badge: 'ak-badge--green' },
  { value: 'scrapped',    label: 'Scrapped',    badge: 'ak-badge--red' },
  { value: 'returned',    label: 'Returned',    badge: 'ak-badge--blue' },
  { value: 'missing',     label: 'Missing',     badge: 'ak-badge--red' },
];

export const itemStatusMeta = (value) =>
  ITEM_STATUSES.find((s) => s.value === value) || { value, label: value || '—', badge: 'ak-badge--gray' };

/**
 * Trimmed text, or null. The sticker fields are stored ALONGSIDE the product
 * and serial foreign keys because a defective unit's label may match nothing in
 * the catalogue — but an empty string is not a mismatch, it is no answer, and
 * must reach the API as null rather than as ''.
 */
const orNull = (v) => {
  const s = typeof v === 'string' ? v.trim() : v;
  return s === '' || s === undefined ? null : s;
};

/** An id the API can use, or null — '' and 0 are both "not chosen" here. */
const idOrNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v) || null);

/** A count, or null. Unlike an id, ZERO is a real answer and must survive. */
const piecesOrNull = (v) => {
  if (v === '' || v === null || v === undefined) return null;
  const n = parseInt(v, 10);
  return Number.isNaN(n) ? null : n;
};

/**
 * Build one `items` row. Kept out of the components so the shape the API is
 * sent is stated in exactly one place, and so it can be unit-tested without a
 * DOM.
 */
export const toItemPayload = ({
  productId, batchId, serialId, quantity, unitRole, modelCode, serialNumber,
  expectedProductId, expectedQuantity,
}) => ({
  product_id: idOrNull(productId),
  batch_id: idOrNull(batchId),
  serial_id: idOrNull(serialId),
  quantity: Math.max(1, parseInt(quantity, 10) || 0),
  unit_role: unitRole || 'single',
  model_code: orNull(modelCode),
  serial_number: orNull(serialNumber),
  // The wrong/correct pair, on a return. Recorded and printed; nothing is
  // raised from either — getting the right unit to the customer is a normal
  // issuance someone raises separately, and a report that silently created one
  // would move stock nobody agreed to.
  expected_product_id: idOrNull(expectedProductId),
  // NOT idOrNull: zero is a legitimate ordered quantity — nothing was ordered
  // and something arrived — and idOrNull would turn it into "not answered".
  expected_quantity: piecesOrNull(expectedQuantity),
});

/** The client's document block, or nulls. Optional in full: see ClientFormBlock. */
export const toClientPayload = (f) => ({
  dealer_name: orNull(f.dealerName),
  dealer_phone: orNull(f.dealerPhone),
  dealer_mobile: orNull(f.dealerMobile),
  dealer_email: orNull(f.dealerEmail),
  branch_address: orNull(f.branchAddress),
  customer_name: orNull(f.customerName),
  customer_contact: orNull(f.customerContact),
  site_address: orNull(f.siteAddress),
  purchased_at: orNull(f.purchasedAt),
  received_at_dor: orNull(f.receivedAtDor),
});
