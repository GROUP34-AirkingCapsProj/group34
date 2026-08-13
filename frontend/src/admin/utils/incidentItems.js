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
 * LABELS ONLY. Mirrors App\Models\IncidentItem::OUTCOMES for wording; it is
 * NEVER the list a screen offers.
 *
 * Which outcomes are available for a given unit is served per item as
 * `allowed_outcomes`, filtered by that unit's warranty state and enforced with
 * a 422 in setOutcome(). A client that re-derived the rule would offer a
 * warranty claim on an out-of-warranty unit — which wastes a warranty officer's
 * time and stays invisible until they open the claim and find nothing to claim
 * against.
 *
 * `removesStock` mirrors IncidentItem::OUTCOMES_THAT_REMOVE_STOCK exactly. The
 * flag it replaced said restock was the only outcome that keeps the goods,
 * which is no longer true and never was safe to assert here: `claim_warranty`
 * holds the unit in the building while a claim is raised against it, and
 * `repair` holds it while it is fixed. Treating either as a departure would
 * delete stock that is sitting on the bench.
 */
export const ITEM_OUTCOMES = [
  {
    value: 'restock',
    label: 'Put back into stock',
    removesStock: false,
    help: 'The unit is fine. It becomes pickable again; the count does not change, because filing never deducted it.',
  },
  {
    value: 'return_to_supplier',
    label: 'Return to supplier',
    removesStock: true,
    help: 'Goes back to the supplier. Confirming deducts it from the stock count.',
  },
  {
    value: 'claim_warranty',
    label: 'Claim on the warranty',
    removesStock: false,
    help: 'The unit stays here while a warranty claim is raised against it. Nothing is deducted, and no claim record is created from this report — a warranty officer raises that separately.',
  },
  {
    value: 'repair',
    label: 'Send for repair',
    removesStock: false,
    help: 'The unit stays here while it is fixed. Nothing is deducted — it is on the bench, not gone.',
  },
  {
    value: 'scrap',
    label: 'Scrap',
    removesStock: true,
    help: 'Destroyed or unsellable. Confirming deducts it from the stock count.',
  },
  {
    value: 'write_off',
    label: 'Write off as lost',
    removesStock: true,
    help: 'Gone and not coming back. Confirming deducts it and records the loss.',
  },
];

export const outcomeMeta = (value) => ITEM_OUTCOMES.find((o) => o.value === value) || null;

export const outcomeLabel = (value) =>
  outcomeMeta(value)?.label || (value ? String(value).replace(/_/g, ' ') : '—');

/**
 * Does confirming this outcome deduct the unit?
 *
 * Defaults to FALSE for an outcome this mirror has not heard of. An unknown
 * value is far more likely to be a new non-destructive ending than a new
 * deduction, and the alternative — captioning "deducted on confirmation" under
 * something that is not — tells an admin a write-off is happening when it is
 * not.
 */
export const outcomeRemovesStock = (value) => outcomeMeta(value)?.removesStock === true;

/**
 * Whether a unit is still covered. Served per item as `warranty_state`.
 *
 * `expired` and `none` offer the same choices but are not the same fact: one
 * unit was sold and its cover ran out, the other was never registered at all.
 * They are shown differently so an inspector can tell which one they are
 * looking at.
 */
export const WARRANTY_STATES = [
  {
    value: 'in_warranty',
    label: 'Under warranty',
    badge: 'ak-badge--green',
    help: 'An unexpired warranty record covers this serial, so a warranty claim is available.',
  },
  {
    value: 'expired',
    label: 'Warranty expired',
    badge: 'ak-badge--amber',
    help: 'This unit was sold and registered, but its cover has run out. A warranty claim is not available.',
  },
  {
    value: 'none',
    label: 'Not sold yet',
    // The old wording — "no warranty record exists, it was never registered" —
    // read as lost data on a unit that is simply still in stock. A customer
    // warranty begins at SALE, so a unit nobody has bought has none yet, and
    // that is the normal state of everything on the shelf rather than a fault.
    // The supplier badge beside this one carries the cover that does apply.
    help: 'This unit has not been sold, so no customer warranty has started. Check the supplier warranty below for cover on returns.',
    badge: 'ak-badge--gray',
  },
];

/**
 * The SUPPLIER's cover, served per item as `supplier_warranty`.
 *
 * A separate clock from the customer one above, and the two disagree constantly
 * by design: stock on the shelf is under supplier cover with no customer
 * warranty at all, which is exactly the case the customer badge alone could not
 * describe. This is what decides whether a defective unit can go back.
 */
export const SUPPLIER_WARRANTY_STATES = [
  {
    value: 'in_warranty',
    label: 'Supplier warranty active',
    badge: 'ak-badge--green',
    help: 'The supplier still covers this unit, so a defective one can be returned to them.',
  },
  {
    value: 'expired',
    label: 'Supplier warranty expired',
    badge: 'ak-badge--amber',
    help: 'The supplier’s cover has run out, so a defective unit can no longer be returned to them as a warranty claim.',
  },
  {
    value: 'none',
    label: 'No supplier cover on record',
    badge: 'ak-badge--gray',
    help: 'This unit could not be traced to a receiving with a warranty term, so there is no supplier cover to return it under.',
  },
];

export const supplierWarrantyMeta = (value) =>
  SUPPLIER_WARRANTY_STATES.find((s) => s.value === value) || null;

/** "active until 2029-08-12 · Supplier A · RCV-0044 / PO-0064", skipping whatever is missing. */
export const supplierWarrantyDetail = (sw) => {
  if (!sw) return '';
  return [
    sw.end_date ? `until ${sw.end_date}` : null,
    sw.supplier_name || null,
    [sw.receiving_number, sw.po_number].filter(Boolean).join(' / ') || null,
  ].filter(Boolean).join(' · ');
};

export const warrantyStateMeta = (value) =>
  WARRANTY_STATES.find((s) => s.value === value) || null;

/**
 * Where a unit currently stands. Mirrors App\Models\IncidentItem::STATUSES.
 *
 * `quarantined` is the one that surprises people: the goods are physically
 * present and still counted in quantity_on_hand — they are simply unpickable
 * until someone decides. Nothing has been deducted.
 *
 * `under_repair` and `awaiting_warranty_claim` are the two settled statuses
 * where the goods are STILL HERE. They are the resting places of `repair` and
 * `claim_warranty`, and they deduct nothing.
 */
export const ITEM_STATUSES = [
  { value: 'quarantined', label: 'Quarantined', badge: 'ak-badge--amber' },
  { value: 'available',   label: 'Back in stock', badge: 'ak-badge--green' },
  { value: 'under_repair', label: 'Under repair', badge: 'ak-badge--blue' },
  { value: 'awaiting_warranty_claim', label: 'Awaiting warranty claim', badge: 'ak-badge--blue' },
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
