import {
  DOC_MODES, SECTION, sectionsFor, sectionState, evidenceRequirementsFor,
  formFromReport, outstandingRequirements, requirementsFor,
  whatInspectionIsMissing, whatIsMissing,
} from './incidentDocument';

const unit = (over = {}) => ({ product_id: '7', pieces: 1, batch_id: '', ...over });

const form = (over = {}) => ({
  reason: 'defective',
  locationId: '1',
  narrative: 'the panel is cracked',
  rootCause: 'supplier',
  ...over,
});

describe('sectionsFor', () => {
  it('gives a defective report the whole document', () => {
    const keys = sectionsFor('defective');
    expect(keys).toEqual([
      SECTION.TYPE, SECTION.DEALER, SECTION.UNITS, SECTION.UNIT_DEFECT, SECTION.PROBLEM,
      SECTION.ATTACHMENTS, SECTION.FINDINGS, SECTION.OUTCOMES, SECTION.SIGNATURES,
    ]);
  });

  it('adds the counterparty and reason block on a return', () => {
    expect(sectionsFor('return', 'wrong_product')).toContain(SECTION.RETURN);
    expect(sectionsFor('defective')).not.toContain(SECTION.RETURN);
  });

  // The unit is gone. Demanding a photograph of an absent item teaches people
  // to fake evidence — but it is still inspected and still settled.
  it('asks a missing report for no attachments, yet still inspects it', () => {
    const keys = sectionsFor('missing');
    expect(keys).not.toContain(SECTION.ATTACHMENTS);
    expect(keys).toContain(SECTION.OUTCOMES);
    expect(keys).toContain(SECTION.SIGNATURES);
  });

  // Mirrors IncidentService::INSPECTION_EXEMPT_REASONS — neither quarantines
  // anything, so there is no inspection to await and nothing below the rule.
  it('gives a zone mismatch nothing below the rule', () => {
    expect(sectionsFor('zone_mismatch')).toEqual([SECTION.TYPE, SECTION.STOCK, SECTION.PROBLEM]);
  });

  it('drops even the product block for `other`, which moves no stock', () => {
    expect(sectionsFor('other')).toEqual([SECTION.TYPE, SECTION.PROBLEM]);
  });

  it('treats an unrecognised type as `other` rather than throwing', () => {
    expect(sectionsFor('not_a_real_type')).toEqual([SECTION.TYPE, SECTION.PROBLEM]);
  });
});

describe('evidenceRequirementsFor', () => {
  // Reason-driven, not type-driven: a wrong delivery proves the delivery was
  // wrong; it says nothing about whether the box survived.
  it('asks a wrong-product return for the pair and the receipt, not the box', () => {
    const reqs = evidenceRequirementsFor('return', 'wrong_product');
    expect(reqs).toEqual(expect.arrayContaining([
      'wrong_product_photo', 'correct_product_photo', 'receipt_or_dor',
    ]));
    expect(reqs).not.toContain('box_two_sides');
  });

  it('asks an unwanted return whether the goods are still sellable', () => {
    const reqs = evidenceRequirementsFor('return', 'no_longer_needed');
    expect(reqs).toEqual(expect.arrayContaining([
      'box_two_sides', 'accessories_warranty', 'returned_goods_photo', 'receipt_or_dor',
    ]));
    expect(reqs).not.toContain('wrong_product_photo');
  });

  it('asks a missing report for nothing', () => {
    expect(evidenceRequirementsFor('missing')).toEqual([]);
  });
});

describe('sectionState', () => {
  // The reporter's half is locked once filed — an inspector checks the claim,
  // they do not get to edit it — but it is never hidden.
  it('locks the reporter half and opens the inspector half in `inspect`', () => {
    expect(sectionState(SECTION.UNITS, DOC_MODES.INSPECT)).toBe('readonly');
    expect(sectionState(SECTION.FINDINGS, DOC_MODES.INSPECT)).toBe('editable');
  });

  it('shows the inspector half as awaiting, not absent, while filing', () => {
    expect(sectionState(SECTION.UNITS, DOC_MODES.FILE)).toBe('editable');
    expect(sectionState(SECTION.OUTCOMES, DOC_MODES.FILE)).toBe('awaiting');
  });

  it('leaves every section readable and unwritable for the admin', () => {
    [SECTION.DEALER, SECTION.UNITS, SECTION.ATTACHMENTS, SECTION.SIGNATURES]
      .forEach((k) => expect(sectionState(k, DOC_MODES.DECIDE)).toBe('readonly'));
  });
});

describe('whatIsMissing', () => {
  it('is empty when a defective report is complete', () => {
    expect(whatIsMissing(form(), [unit()])).toEqual([]);
  });

  it('names the location and the narrative when they are blank', () => {
    const out = whatIsMissing(form({ locationId: '', narrative: '  ' }), [unit()]);
    expect(out).toEqual(expect.arrayContaining([
      'the location this happened at', 'a description of what happened',
    ]));
  });

  // toItemPayload floors a quantity at 1, so an empty box would otherwise
  // validate as a one-piece write-off.
  it('refuses a unit with a product but no quantity', () => {
    expect(whatIsMissing(form(), [unit({ pieces: 0 })]))
      .toContain('a quantity of at least 1 on every unit');
  });

  // RetypeResolution refuses to resolve an incident with no batch, so a
  // wrong-type report without one would sit open forever.
  it('requires a batch on a wrong-type report', () => {
    expect(whatIsMissing(form({ reason: 'wrong_type' }), [unit()]))
      .toContain('the batch the mislabelled stock is in');
  });

  it('requires both return facts', () => {
    const out = whatIsMissing(form({ reason: 'return' }), [unit()]);
    expect(out).toEqual(expect.arrayContaining([
      'who the goods are coming from, or going back to',
      'why the goods are being returned',
    ]));
  });

  // The pair is the whole point of a wrong-product return.
  it('requires what should have been delivered on a wrong-product return', () => {
    const f = form({ reason: 'return', returnCounterparty: 'customer', returnReasonCode: 'wrong_product' });
    expect(whatIsMissing(f, [unit()])).toContain('what should have been delivered, on every unit');
    expect(whatIsMissing(f, [unit({ expected_product_id: '9' })]))
      .not.toContain('what should have been delivered, on every unit');
  });

  // Zero is a legitimate ordered quantity — nothing was ordered and something
  // arrived — so it must not read as "unanswered".
  it('accepts an ordered quantity of zero on a wrong-quantity return', () => {
    const f = form({ reason: 'return', returnCounterparty: 'supplier', returnReasonCode: 'wrong_quantity' });
    expect(whatIsMissing(f, [unit({ expected_quantity: 0 })]))
      .not.toContain('the quantity that was ordered, on every unit');
    expect(whatIsMissing(f, [unit({ expected_quantity: '' })]))
      .toContain('the quantity that was ordered, on every unit');
  });

  it('asks a zone mismatch for the batch and the real zone, and for no units', () => {
    const out = whatIsMissing(form({ reason: 'zone_mismatch', productId: '7' }), []);
    expect(out).toEqual(expect.arrayContaining([
      'the batch that is in the wrong place', 'the zone it is actually in',
    ]));
    expect(out).not.toContain('at least one unit');
  });

  it('asks `other` for nothing beyond a location and a description', () => {
    expect(whatIsMissing(form({ reason: 'other' }), [])).toEqual([]);
  });
});

describe('requirementsFor / outstandingRequirements', () => {
  // The ASC report is conditional on a FACT the inspector states, not on the
  // type — it appears the moment they tick the box, and the checklist has to
  // grow with it or an inspector submits without the report they just said
  // exists.
  it('adds the ASC report only once the ASC box is ticked', () => {
    expect(requirementsFor('defective', false)).not.toContain('asc_service_report');
    expect(requirementsFor('defective', true)).toContain('asc_service_report');
  });

  it('leaves a reason that asks for nothing empty, ASC or not', () => {
    expect(requirementsFor('missing', true)).toEqual([]);
  });

  // A skip is data, not an absence: it is what lets the admin see that the box
  // photo is missing BECAUSE the box was discarded, rather than seeing a thin
  // report and guessing.
  it('treats a written skip as satisfying the requirement', () => {
    const evidence = [{ requirement: 'defect_photo', skipped_reason: 'the unit was collected' }];
    expect(outstandingRequirements('defective', false, evidence)).not.toContain('defect_photo');
  });

  it('counts a requirement with neither a photo nor a reason as outstanding', () => {
    expect(outstandingRequirements('defective', false, [])).toContain('defect_photo');
  });
});

describe('whatInspectionIsMissing', () => {
  const args = (over = {}) => ({
    reason: 'defective',
    checkedByAsc: false,
    evidence: [
      { requirement: 'defect_photo', photo_path: 'incidents/a.jpg' },
      { requirement: 'model_serial_sticker', photo_path: 'incidents/b.jpg' },
      { requirement: 'box_two_sides', skipped_reason: 'discarded' },
      { requirement: 'accessories_warranty', photo_path: 'incidents/c.jpg' },
    ],
    items: [{ item_id: 1, status: 'quarantined', outcome: 'restock' }],
    ...over,
  });

  it('is satisfied when every requirement is answered and every unit decided', () => {
    expect(whatInspectionIsMissing(args())).toEqual([]);
  });

  it('names the units still without an outcome', () => {
    const out = whatInspectionIsMissing(args({
      items: [
        { item_id: 1, status: 'quarantined', outcome: null },
        { item_id: 2, status: 'quarantined', outcome: null },
      ],
    }));
    expect(out).toContain('an outcome on 2 units');
  });

  // Already applied by a confirmation. IncidentItemService refuses to change a
  // settled item, so counting one as outstanding would make the report
  // permanently unsubmittable.
  it('does not ask for an outcome on a unit that is already settled', () => {
    expect(whatInspectionIsMissing(args({
      items: [{ item_id: 1, status: 'scrapped', outcome: null }],
    }))).toEqual([]);
  });

  it('names an outstanding requirement through the label it is given', () => {
    const out = whatInspectionIsMissing(args({
      evidence: [],
      requirementLabel: (req) => (req === 'defect_photo' ? 'Picture of the defect' : req),
    }));
    expect(out[0]).toMatch(/^Picture of the defect —/);
  });

  // Ticking the ASC box mid-inspection re-opens the checklist. A screen that
  // let the submit through here would file a report claiming a service centre
  // saw the unit with no service report behind it.
  it('re-opens the checklist when the ASC box is ticked', () => {
    expect(whatInspectionIsMissing(args({ checkedByAsc: true })))
      .toHaveLength(1);
  });
});

describe('formFromReport', () => {
  const report = {
    incident_id: 5,
    reason: 'return',
    location_id: 3,
    narrative: 'came back on the truck',
    return_counterparty: 'customer',
    return_reason_code: 'wrong_product',
    dealer_name: 'Northgate Aircon',
    customer_name: null,
    items: [{
      item_id: 11, product_id: 7, batch_id: 4, unit_role: 'indoor',
      model_code: 'AK-9', serial_number: 'SN-1', quantity: 25, pieces_per_package: 10,
      expected_product_id: 9, expected_quantity: 0,
    }],
  };

  it('reads the reporter’s answers back into the fields they were typed into', () => {
    const f = formFromReport(report);
    expect(f.reason).toBe('return');
    expect(f.locationId).toBe('3');
    expect(f.returnCounterparty).toBe('customer');
    expect(f.dealerName).toBe('Northgate Aircon');
  });

  // A controlled input handed null goes uncontrolled and React warns; every
  // unanswered field has to arrive as ''.
  it('turns an unanswered field into an empty string, never null', () => {
    expect(formFromReport(report).customerName).toBe('');
    expect(formFromReport({}).narrative).toBe('');
  });

  it('gives one row per saved unit, keyed on its item id', () => {
    const [row] = formFromReport(report).units;
    expect(row.uid).toBe('item-11');
    expect(row.model_code).toBe('AK-9');
    expect(row.unit_role).toBe('indoor');
  });

  // Quantities are stored in PIECES. Re-splitting them by the pack size is what
  // makes the locked row read the way it was entered — "2 Box + 5 pcs", not 25.
  it('re-splits a piece count across packs and loose pieces', () => {
    const [row] = formFromReport(report).units;
    expect(row.quantity).toBe(25);
    expect(row.packs).toBe(2);
    expect(row.loose_pieces).toBe(5);
  });

  // Zero is a real ordered quantity — nothing was ordered and something
  // arrived — and it must not read back as "not answered".
  it('keeps an ordered quantity of zero', () => {
    expect(formFromReport(report).units[0].expected_quantity).toBe('0');
  });

  // A report with no items still renders the Units section, and a units list
  // of length zero is indistinguishable from a report about nothing.
  it('falls back to one blank row when the report has no items', () => {
    expect(formFromReport({ reason: 'other' }).units).toHaveLength(1);
  });
});
