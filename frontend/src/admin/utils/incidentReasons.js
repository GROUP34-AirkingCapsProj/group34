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
    value: 'not_found',
    label: 'Not found',
    pickerLabel: "I can't find it on the shelf",
    help: 'Filing this opens an investigation record. The missing count is visible to reviewers — this is a tracked report, not a silent skip.',
    group: 'Missing stock',
    badge: 'ak-badge--red',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: true,
  },
  {
    value: 'theft',
    label: 'Theft',
    pickerLabel: 'It was taken',
    help: 'Recorded separately from "not found" — this is the number shrinkage reporting works from.',
    group: 'Missing stock',
    badge: 'ak-badge--red',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: false,
  },
  {
    value: 'damaged',
    label: 'Damaged',
    pickerLabel: "It's there but damaged",
    help: 'The stock exists but cannot be sold.',
    group: 'Damage',
    badge: 'ak-badge--amber',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: true,
  },
  {
    value: 'delivery_damage',
    label: 'Damaged in delivery',
    pickerLabel: 'Damaged on the way to the customer',
    help: 'Damaged in handling or in transit rather than in storage.',
    group: 'Damage',
    badge: 'ak-badge--amber',
    affectsStock: true,
    outcome: 'writeoff',
    duringPick: false,
  },
  {
    value: 'wrong_type',
    label: 'Wrong type on shelf',
    pickerLabel: "Wrong item — the label doesn't match what's physically here",
    help: 'The batch was received as the wrong product. Resolving re-types it.',
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
    help: 'Nothing is missing — the batch record points at the wrong zone.',
    group: 'Wrong item',
    badge: 'ak-badge--gray',
    affectsStock: true,
    outcome: 'relocate',
    duringPick: true,
  },
  {
    value: 'customer_return',
    label: 'Customer return',
    pickerLabel: 'Customer returned it',
    help: 'The wrong item was delivered and came back. Resolving puts the stock back on its original batch.',
    group: 'Return',
    badge: 'ak-badge--green',
    affectsStock: true,
    outcome: 'restock',
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
