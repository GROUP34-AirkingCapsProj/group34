/**
 * Single source of truth for incident reason metadata.
 *
 * Before this existed the labels lived in three places — IncidentReportsPage,
 * IncidentResolveDrawer, and picking/IncidentModal — each with its own list.
 * `outcome` mirrors the backend strategy map (App\Services\Incidents\*), so the
 * resolve drawer can derive its confirmation copy instead of hardcoding which
 * reasons write stock off.
 */

export const INCIDENT_REASONS = [
  {
    value: 'missing',
    label: 'Missing',
    pickerLabel: "I can't find it on the shelf",
    help: 'Filing this quarantines the units so nobody can pick them, but the stock count does not change yet. An inspector examines them, then an admin confirms — only then is the loss deducted.',
    group: 'Missing stock',
    badge: 'ak-badge--red',
    affectsStock: true,
    outcome: 'missing',
    duringPick: true,
  },
  {
    value: 'defective',
    label: 'Defective',
    pickerLabel: "It's there but it's damaged or faulty",
    help: 'The stock exists but cannot be sold. Filing quarantines it so it cannot be picked; an inspector then records the evidence and decides whether it goes back to the supplier or is scrapped, and an admin confirms.',
    group: 'Damage',
    badge: 'ak-badge--amber',
    affectsStock: true,
    outcome: 'defective',
    duringPick: true,
  },
  {
    value: 'wrong_type',
    label: 'Wrong type on shelf',
    pickerLabel: "Wrong item — the label doesn't match what's physically here",
    help: 'The label does not match what is physically there. Filing quarantines the units; the inspector records what they actually are, and an admin confirms.',
    group: 'Wrong item',
    badge: 'ak-badge--blue',
    affectsStock: true,
    outcome: 'retype',
    duringPick: true,
  },
  {
    value: 'zone_mismatch',
    label: 'Zone mismatch',
    pickerLabel: "It's here, just in a different zone",
    help: 'Nothing is missing and nothing moves in the system — the record is right and the item is physically in the wrong place. Reporting it tells someone to go move it back.',
    group: 'Wrong item',
    badge: 'ak-badge--gray',
    affectsStock: true,
    outcome: 'relocate',
    duringPick: true,
  },
  {
    value: 'return',
    label: 'Return',
    pickerLabel: 'Something is being returned',
    help: 'Stock coming back from a customer, or going back to a supplier. Filing quarantines it; nothing is sellable again until an inspector has looked at it and an admin has confirmed.',
    group: 'Return',
    badge: 'ak-badge--green',
    affectsStock: true,
    outcome: 'return',
    duringPick: false,
  },
  {
    value: 'other',
    label: 'Other',
    pickerLabel: 'Something else',
    help: 'Anything that needs a record and a reviewer but does not move stock. No product or quantity required.',
    group: 'Other',
    badge: 'ak-badge--gray',
    affectsStock: false,
    outcome: 'none',
    duringPick: false,
  },
];

/** Missing: why the reporter thinks it is gone. Mirrors IncidentReport::SUSPECTED_CAUSES. */
export const SUSPECTED_CAUSES = [
  { value: 'theft', label: 'Taken — suspected theft' },
  { value: 'misplaced', label: 'Misplaced somewhere in the building' },
  { value: 'unknown', label: "Don't know" },
];

/** Defective: where the fault came from. Mirrors IncidentReport::ROOT_CAUSES. */
export const ROOT_CAUSES = [
  { value: 'supplier', label: 'Arrived faulty from the supplier' },
  { value: 'internal', label: 'Damaged here — handling or storage' },
  { value: 'delivery', label: 'Damaged on the way to the customer' },
  { value: 'other', label: 'Something else' },
];

/**
 * The two directions a return can travel. Mirrors IncidentReport::RETURN_DIRECTIONS.
 *
 * RETIRED in favour of the counterparty and reason code below, which are two
 * independent facts rather than one field conflating who the counterparty was
 * with which way the goods travel. Kept only until the wizard is deleted.
 */
export const RETURN_DIRECTIONS = [
  { value: 'from_customer', label: 'Coming back from a customer' },
  { value: 'to_supplier', label: 'Going back to the supplier' },
];

/**
 * Who the goods came from, or go back to. Mirrors
 * IncidentReport::RETURN_COUNTERPARTIES.
 *
 * Direction is DERIVED from this and never asked: a reporter who answers "from
 * a customer" and then "outbound" has created a contradiction with no correct
 * resolution. `customer` and `branch` are inbound; `supplier` is outbound.
 */
export const RETURN_COUNTERPARTIES = [
  {
    value: 'customer',
    label: 'From a customer',
    help: 'The goods are coming back to us. They were issued out long ago and are in no batch, so nothing is deducted — they are registered as arriving and stay out of the stock count until an admin accepts them back.',
  },
  {
    value: 'branch',
    label: 'From another branch',
    help: 'An interbranch return. Treated exactly like a customer return: the goods are arriving, and are not counted until accepted.',
  },
  {
    value: 'supplier',
    label: 'Back to the supplier',
    help: 'The goods are here now and are going out. They are quarantined so nobody can pick them; the count drops only when an admin confirms.',
  },
];

/**
 * Why. Mirrors IncidentReport::RETURN_REASON_CODES.
 *
 * Defective is deliberately absent. A faulty unit is an inspection OUTCOME —
 * the inspector decides whether it is repaired, claimed under warranty, sent
 * back or scrapped — and offering it here would let a reporter pre-empt that.
 */
export const RETURN_REASON_CODES = [
  {
    value: 'wrong_product',
    label: 'The wrong product was delivered',
    help: 'Record both what arrived and what should have. Nothing is raised from it — getting the right unit out is a normal issuance someone raises separately.',
  },
  {
    value: 'wrong_quantity',
    label: 'The wrong quantity was delivered',
    help: 'Record what was ordered. The delivered figure is the quantity on each unit below, so the discrepancy is written down rather than implied.',
  },
  {
    value: 'no_longer_needed',
    label: 'No longer needed',
    help: 'The goods are fine and simply unwanted. The evidence asked for is about whether they are still sellable.',
  },
];

const BY_VALUE = INCIDENT_REASONS.reduce((acc, r) => { acc[r.value] = r; return acc; }, {});

/** Metadata for a reason, or a safe stand-in for an unrecognised one. */
export const reasonMeta = (value) =>
  BY_VALUE[value] || {
    value,
    label: value,
    pickerLabel: value,
    help: '',
    group: 'Other',
    badge: 'ak-badge--gray',
    affectsStock: false,
    outcome: 'none',
    duringPick: false,
  };

export const reasonLabel = (value) => reasonMeta(value).label;
export const reasonBadge = (value) => reasonMeta(value).badge;

/** The four reasons the barcode scanner can raise mid-pick. */
export const PICKING_REASONS = INCIDENT_REASONS.filter((r) => r.duringPick);

/** Reasons grouped for the standalone file form's radio list. */
export const REASON_GROUPS = INCIDENT_REASONS.reduce((groups, reason) => {
  const found = groups.find((g) => g.name === reason.group);
  if (found) found.reasons.push(reason);
  else groups.push({ name: reason.group, reasons: [reason] });
  return groups;
}, []);
