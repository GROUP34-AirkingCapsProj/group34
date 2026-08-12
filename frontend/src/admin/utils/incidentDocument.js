import { reasonMeta } from './incidentReasons';

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
 * What each type is asked to photograph. Mirrors
 * App\Models\IncidentEvidence::requiredFor() — reason-driven, not type-driven.
 *
 * Exported so the reporter can see, in `file` mode, what the inspector will be
 * asked for. `missing` asks for nothing: the unit is gone, and demanding a
 * photograph of an absent item teaches people to fake evidence.
 */
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
