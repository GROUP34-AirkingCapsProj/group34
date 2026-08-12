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

/**
 * Build one `items` row. Kept out of the components so the shape the API is
 * sent is stated in exactly one place, and so it can be unit-tested without a
 * DOM.
 */
export const toItemPayload = ({
  productId, batchId, serialId, quantity, unitRole, modelCode, serialNumber,
}) => ({
  product_id: idOrNull(productId),
  batch_id: idOrNull(batchId),
  serial_id: idOrNull(serialId),
  quantity: Math.max(1, parseInt(quantity, 10) || 0),
  unit_role: unitRole || 'single',
  model_code: orNull(modelCode),
  serial_number: orNull(serialNumber),
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
