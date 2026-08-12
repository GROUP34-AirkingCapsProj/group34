import {
  DOC_MODES, SECTION, sectionsFor, sectionState, evidenceRequirementsFor, whatIsMissing,
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
