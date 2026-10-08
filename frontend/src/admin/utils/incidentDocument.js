import { reasonMeta } from './incidentReasons';
import { newUnit } from './incidentUnits';

/**
 * The pure parts of the unified incident document: which sections a type shows,
 * who may write in them in each mode, and what is still unanswered.
 *
 * The report is ONE file that travels. The reporter fills their half and
 * submits; the same document reaches the inspector, who adds evidence, findings
 * and outcomes to it; the same document reaches the admin. Three modes over one
 * component, not three screens that share an id.
 *
 * These rules live outside the component because all three modes read them and
 * a unit test is cheaper than rendering the whole form to find out what a
 * wrong-product return asks for.
 */

/** Who is writing. Mirrors the spec's mode table. */
export const DOC_MODES = { FILE: 'file', INSPECT: 'inspect', DECIDE: 'decide' };

export const SECTION = {
  TYPE: 'type',
  RETURN: 'return',
  DEALER: 'dealer',
  UNITS: 'units',
  STOCK: 'stock',
  UNIT_DEFECT: 'unit_defect',
  PROBLEM: 'problem',
  ATTACHMENTS: 'attachments',
  FINDINGS: 'findings',
  OUTCOMES: 'outcomes',
  SIGNATURES: 'signatures',
};

/**
 * Everything below the rule on the printed form. The document changes hands
 * here: above it is the reporter's, below it is the inspector's, and the admin
 * writes in neither.
 */
export const INSPECTOR_SECTIONS = [
  SECTION.ATTACHMENTS, SECTION.FINDINGS, SECTION.OUTCOMES, SECTION.SIGNATURES,
];

export const isInspectorSection = (key) => INSPECTOR_SECTIONS.includes(key);

/**
 * The two outcomes that create no `incident_items`, mirroring
 * IncidentService::INSPECTION_EXEMPT_REASONS. Neither quarantines anything, so
 * neither has units to enumerate or an inspection to await — which is why a
 * zone mismatch and an `other` show nothing at all below the rule.
 */
const OUTCOMES_WITHOUT_UNITS = ['relocate', 'none'];

/**
 * The same two, by REASON rather than by outcome — a direct mirror of
 * IncidentService::INSPECTION_EXEMPT_REASONS.
 *
 * Exported because three screens need it and each had grown its own copy: the
 * inspector's queue excludes these (there is nothing to inspect), and the
 * admin's document allows a decision on one straight from Open, since
 * `confirm()` bypasses the Awaiting Approval guard for exactly these reasons.
 */
export const INSPECTION_EXEMPT_REASONS = ['zone_mismatch', 'other'];

/**
 * The human name and the explanatory help for every requirement.
 *
 * Mirrors App\Models\IncidentEvidence::REQUIREMENTS. The server is the
 * authority; this list exists so the inspector is told what is still
 * outstanding before they submit rather than after a 422.
 *
 * IT LIVES HERE, not in EvidenceChecklist, because the admin's read-only
 * summary needs the labels too — and reaching into the checklist for them would
 * drag CameraCapture, useIncidentPhoto, `toast` and the MUTATING half of
 * incidentReportsAPI into the one view whose defining property is that it
 * cannot write. A label lookup is a pure fact about the document, so it belongs
 * beside the other pure facts about the document.
 */
export const EVIDENCE_REQUIREMENTS = [
  {
    value: 'defect_photo',
    label: 'Picture of the defect',
    help: 'The fault itself, close enough to see what is wrong.',
  },
  {
    value: 'model_serial_sticker',
    label: 'Model / serial sticker',
    help: 'The sticker on the unit, readable. Where the label does not match the goods, this is the evidence.',
  },
  {
    value: 'box_two_sides',
    label: 'Two sides of the box',
    help: 'Including the side carrying the serial sticker. Two photos are expected here, not one.',
  },
  {
    value: 'accessories_warranty',
    label: 'Accessories and warranty card',
    help: 'The complete accessories laid out, with the warranty card visible.',
  },
  {
    value: 'asc_service_report',
    label: 'ASC service report',
    help: 'The service centre’s written report.',
  },
  {
    value: 'wrong_product_photo',
    label: 'The product that actually arrived',
    help: 'What was delivered, with its label readable — half of what proves the delivery was wrong.',
  },
  {
    value: 'correct_product_photo',
    label: 'The product that should have arrived',
    help: 'What was ordered — the order line, the catalogue page, or the correct unit. The other half of the pair.',
  },
  {
    value: 'returned_goods_photo',
    label: 'The returned goods',
    help: 'The goods as they came back, so it is on record whether they are still sellable.',
  },
  {
    value: 'receipt_or_dor',
    label: 'Receipt or delivery receipt (DOR)',
    help: 'The document proving the delivery happened. Asked for on every return.',
  },
];

/**
 * Falls back to the raw value with its underscores opened out rather than
 * returning nothing. A requirement the server knows about and this list does not
 * must still render as words — a blank checklist line is the exact failure the
 * read-only summary exists to prevent.
 */
export const requirementMeta = (value) =>
  EVIDENCE_REQUIREMENTS.find((r) => r.value === value)
  || { value, label: String(value || '').replace(/_/g, ' '), help: '' };

/**
 * What each type is asked to photograph. Mirrors
 * App\Models\IncidentEvidence::requiredFor() — reason-driven, not type-driven.
 *
 * Exported so the reporter can see, in `file` mode, what the inspector will be
 * asked for. `missing` asks for nothing: the unit is gone, and demanding a
 * photograph of an absent item teaches people to fake evidence.
 */
/**
 * The requirements this report must answer, including the one that depends on a
 * FACT rather than the type: `asc_service_report` appears the moment the
 * inspector ticks "checked by an ASC".
 *
 * A reason that asks for nothing stays empty — a missing unit has no service
 * report either.
 */
export function requirementsFor(reason, checkedByAsc, returnReasonCode) {
  const base = evidenceRequirementsFor(reason, returnReasonCode);
  if (!checkedByAsc || base.length === 0) return base;
  return [...base, 'asc_service_report'];
}

/**
 * Requirements with neither a photo nor a written skip reason against them.
 *
 * A SKIP SATISFIES A REQUIREMENT. That is the whole point of recording one: the
 * admin sees that the box photo is missing because the box was already
 * discarded, rather than seeing a thin report and guessing.
 */
export function outstandingRequirements(reason, checkedByAsc, evidence, returnReasonCode) {
  const rows = Array.isArray(evidence) ? evidence : [];
  return requirementsFor(reason, checkedByAsc, returnReasonCode).filter(
    (req) => !rows.some((e) => e.requirement === req),
  );
}

export function evidenceRequirementsFor(reason, returnReasonCode) {
  if (reason === 'defective') {
    return ['defect_photo', 'model_serial_sticker', 'box_two_sides', 'accessories_warranty'];
  }
  if (reason === 'return') {
    // A wrong delivery proves THE DELIVERY WAS WRONG — the pair plus the
    // receipt. A wrong quantity or an unwanted return proves WHETHER THE GOODS
    // ARE STILL SELLABLE — the goods, the sticker, the box, the accessories.
    // Neither asks for the other's evidence.
    if (returnReasonCode === 'wrong_product') {
      return ['model_serial_sticker', 'wrong_product_photo', 'correct_product_photo', 'receipt_or_dor'];
    }
    return ['model_serial_sticker', 'box_two_sides', 'accessories_warranty',
      'returned_goods_photo', 'receipt_or_dor'];
  }
  if (reason === 'wrong_type') return ['model_serial_sticker'];
  return [];
}

/**
 * The sections this type shows, in the client's paper order.
 *
 * Sections are shown or not shown by TYPE. They are never hidden by progress:
 * an inspector must see what the reporter claimed, because that is half of what
 * they are checking, and an admin must see both. Hiding a completed section to
 * tidy the screen is how someone approves what they never read.
 */
export function sectionsFor(reason, returnReasonCode) {
  const { outcome, affectsStock } = reasonMeta(reason);

  // A zone mismatch says the record is right and the item is merely in the
  // wrong aisle; an `other` moves no stock at all. Location, product, what you
  // saw and when — nothing else, and nothing below the rule, because there is
  // no inspection to await.
  if (OUTCOMES_WITHOUT_UNITS.includes(outcome)) {
    return [
      SECTION.TYPE,
      ...(affectsStock ? [SECTION.STOCK] : []),
      SECTION.PROBLEM,
    ];
  }

  const keys = [SECTION.TYPE];

  if (outcome === 'return') keys.push(SECTION.RETURN);
  // The dealer block belongs to the two types that involve a customer at all.
  // A picker filing a theft must never be asked for a customer's site address;
  // there isn't one, and asking teaches people the form is full of fields to
  // ignore.
  if (outcome === 'defective' || outcome === 'return') keys.push(SECTION.DEALER);

  keys.push(SECTION.UNITS);

  // "Unit defect" on the paper form. A missing report has no defect but does
  // have a cause, and the same block asks it — dropping the question because
  // the paper heading says "defect" would lose the only field that separates a
  // suspected theft from a misplacement.
  if (outcome === 'defective' || outcome === 'missing') keys.push(SECTION.UNIT_DEFECT);

  keys.push(SECTION.PROBLEM);

  // ── the document changes hands here ──
  // Shown only when this type is asked for anything. A missing report is
  // inspected and settled like any other, it is simply asked for no photographs.
  if (evidenceRequirementsFor(reason, returnReasonCode).length > 0) keys.push(SECTION.ATTACHMENTS);
  keys.push(SECTION.FINDINGS, SECTION.OUTCOMES, SECTION.SIGNATURES);

  return keys;
}

/** May the reporter's half be written in? Only while it is being filed. */
export const reporterEditable = (mode) => mode === DOC_MODES.FILE;

/** May the inspector's half be written in? Only during the inspection. */
export const inspectorEditable = (mode) => mode === DOC_MODES.INSPECT;

/**
 * How a section behaves in a mode: `editable`, `readonly` (filled in, locked),
 * or `awaiting` (nobody has written here yet, and it is not this person's job).
 */
export function sectionState(key, mode) {
  const mine = isInspectorSection(key) ? inspectorEditable(mode) : reporterEditable(mode);
  if (mine) return 'editable';
  // The inspector's half during filing: rendered empty and greyed rather than
  // absent, so a reporter can see what will be asked later and what is not
  // their job to answer.
  if (isInspectorSection(key) && mode === DOC_MODES.FILE) return 'awaiting';
  return 'readonly';
}

/**
 * What still has to be answered, in words.
 *
 * Returned as sentences rather than a boolean because a disabled button with no
 * explanation is a dead end — the reporter is told which answer is missing.
 *
 * `units` arrive with their `pieces` already resolved: pack arithmetic needs
 * the product catalogue, which has no business in a pure helper.
 */
export function whatIsMissing(form, units) {
  const keys = sectionsFor(form.reason, form.returnReasonCode);
  const has = (k) => keys.includes(k);
  const out = [];
  const rows = Array.isArray(units) ? units : [];

  if (!form.locationId) out.push('the location this happened at');
  if (!String(form.narrative || '').trim()) out.push('a description of what happened');

  if (has(SECTION.RETURN)) {
    if (!form.returnCounterparty) out.push('who the goods are coming from, or going back to');
    if (!form.returnReasonCode) out.push('why the goods are being returned');
  }

  if (has(SECTION.UNITS)) {
    if (rows.some((u) => !u.product_id)) out.push('a product on every unit');
    if (rows.some((u) => u.product_id && !(u.pieces >= 1))) out.push('a quantity of at least 1 on every unit');
    if (!rows.some((u) => u.product_id && u.pieces >= 1)) out.push('at least one unit');

    // A wrong-type report that names no batch can never be resolved:
    // RetypeResolution refuses to act on one, so it would sit open forever.
    if (form.reason === 'wrong_type' && !rows[0]?.batch_id) {
      out.push('the batch the mislabelled stock is in');
    }

    // The wrong/correct pair. Recorded and printed, nothing raised from it —
    // but a wrong-product return that does not say what should have arrived has
    // not recorded the thing it exists to record.
    if (form.returnCounterparty && form.returnReasonCode === 'wrong_product'
      && rows.some((u) => u.product_id && !u.expected_product_id)) {
      out.push('what should have been delivered, on every unit');
    }
    if (form.returnCounterparty && form.returnReasonCode === 'wrong_quantity'
      && rows.some((u) => u.product_id && !(Number(u.expected_quantity) >= 0
        && String(u.expected_quantity).trim() !== ''))) {
      out.push('the quantity that was ordered, on every unit');
    }
  }

  if (has(SECTION.STOCK)) {
    if (reasonMeta(form.reason).affectsStock && !form.productId) out.push('the product involved');
    if (form.reason === 'zone_mismatch') {
      if (!form.batchId) out.push('the batch that is in the wrong place');
      if (!String(form.actualZone || '').trim()) out.push('the zone it is actually in');
    }
  }

  if (has(SECTION.UNIT_DEFECT)) {
    if (form.reason === 'defective' && !form.rootCause) out.push('where the fault came from');
    if (form.reason === 'missing' && !form.suspectedCause) out.push('why you think it is gone');
  }

  return out;
}

/**
 * What the INSPECTION still has to answer, in the same voice.
 *
 * The sibling of whatIsMissing(), for the half below the rule. Two conditions,
 * both of them the server's: every requirement satisfied by a photo or skipped
 * with a written reason, and every quarantined unit given an outcome.
 *
 * Sentences rather than a boolean, and rendered rather than baked into a
 * disabled button, for the reason Task 8 established — a greyed control with no
 * explanation is a dead end. An inspector who cannot see WHICH requirement is
 * outstanding cannot clear it.
 *
 * `requirementLabel` is injected so this stays free of the component that owns
 * the wording. It defaults to the raw code, which reads badly but never lies.
 */
export function whatInspectionIsMissing({
  reason, returnReasonCode, checkedByAsc, evidence, items,
  requirementLabel = (req) => String(req || '').replace(/_/g, ' '),
}) {
  const out = [];
  const rows = Array.isArray(items) ? items : [];

  // Only the units still awaiting a decision. A settled one has already been
  // applied by a confirmation and the service refuses to change it, so counting
  // it as outstanding would make the report permanently unsubmittable.
  const undecided = rows.filter((i) => i.status === 'quarantined' && !i.outcome);
  if (undecided.length > 0) {
    out.push(`an outcome on ${undecided.length} unit${undecided.length === 1 ? '' : 's'}`);
  }

  outstandingRequirements(reason, checkedByAsc, evidence, returnReasonCode).forEach((req) => {
    out.push(`${requirementLabel(req)} — attach a photo, or say why you can’t`);
  });

  return out;
}

/** `datetime-local` wants LOCAL YYYY-MM-DDTHH:mm; toISOString() is UTC and would be off by the offset. */
export function toLocalInputValue(date) {
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`
    + `T${p(date.getHours())}:${p(date.getMinutes())}`;
}

/** '' rather than null/undefined — a controlled input given null goes uncontrolled. */
const str = (v) => (v === null || v === undefined ? '' : String(v));

/**
 * The saved report, read back into the shape the reporter's half renders from.
 *
 * The document is ONE file that travels, so the inspector and the admin see the
 * reporter's answers in the very fields the reporter typed them into — locked,
 * not summarised somewhere else in a different order. A separate read-only
 * rendering would be a second layout free to drift from the one that was filled
 * in, and drift here means an inspector checking a claim that is not the claim
 * that was made.
 */
export function formFromReport(report) {
  const items = Array.isArray(report?.items) ? report.items : [];

  return {
    reason: report?.reason || 'defective',
    occurredAt: report?.occurred_at ? toLocalInputValue(new Date(report.occurred_at)) : '',
    locationId: str(report?.location_id),

    returnCounterparty: str(report?.return_counterparty),
    returnReasonCode: str(report?.return_reason_code),
    returnReason: str(report?.return_reason),
    issuanceId: str(report?.related_issuance_id),

    dealerName: str(report?.dealer_name),
    dealerPhone: str(report?.dealer_phone),
    dealerMobile: str(report?.dealer_mobile),
    dealerEmail: str(report?.dealer_email),
    branchAddress: str(report?.branch_address),

    // One row per saved unit, keyed on the item id so a re-fetch does not
    // remount every row and lose the note being typed into one of them.
    units: items.length > 0
      ? items.map((i) => unitFromItem(i))
      : [newUnit('single')],
    receivedAtDor: str(report?.received_at_dor),

    productId: str(report?.product_id),
    batchId: str(report?.batch_id),
    actualZone: str(report?.actual_zone),

    rootCause: str(report?.root_cause),
    suspectedCause: str(report?.suspected_cause),
    lastKnownLocation: str(report?.last_known_location),

    customerName: str(report?.customer_name),
    customerContact: str(report?.customer_contact),
    siteAddress: str(report?.site_address),
    purchasedAt: str(report?.purchased_at),
    narrative: str(report?.narrative),
  };
}

/**
 * One saved item as a unit row.
 *
 * `quantity` is stored in PIECES. The pack fields are re-derived from the
 * product's pack size purely so the locked row reads the way the reporter
 * entered it — "2 Box + 5 pcs" rather than a bare 25 — and nothing is sent back
 * from a locked row, so the split can only ever be cosmetic.
 */
function unitFromItem(item) {
  const per = Number(item?.pieces_per_package) || 0;
  const pieces = Number(item?.quantity) || 0;

  return {
    uid: `item-${item.item_id}`,
    item_id: item.item_id,
    product_id: str(item.product_id),
    batch_id: str(item.batch_id),
    serial_id: item.serial_id ?? null,
    unit_role: item.unit_role || 'single',
    model_code: str(item.model_code),
    serial_number: str(item.serial_number),
    packs: per > 1 ? Math.floor(pieces / per) : 0,
    loose_pieces: per > 1 ? pieces % per : 0,
    quantity: pieces,
    expected_product_id: str(item.expected_product_id),
    expected_quantity: item.expected_quantity === null || item.expected_quantity === undefined
      ? ''
      : String(item.expected_quantity),
  };
}
