import {
  buildIncidentReportHtml, printableUnits, relatedDocsStatus, unitDefectText,
} from './incidentReportDocument';

/** A defective split system: an indoor unit and an outdoor unit on one report. */
const splitSystemReport = () => ({
  incident_id: 7,
  incident_number: 'INC-0007',
  reason: 'defective',
  root_cause: 'supplier',
  status_name: 'Awaiting Inspection',
  location_name: 'Panabo Warehouse',
  occurred_at: '2026-08-10T02:00:00+08:00',
  narrative: 'Panel cracked in transit.',
  inspection_findings: 'Compressor sound, panel unusable.',
  dealer_name: 'Sunrise Aircon Trading',
  dealer_phone: '(082) 555 0101',
  dealer_mobile: '0917 555 0102',
  dealer_email: 'sunrise@example.com',
  branch_address: 'Quezon Blvd, Panabo City',
  customer_name: 'Maria Santos',
  customer_contact: '0918 222 3333',
  site_address: '12 Mabini St, Tagum City',
  purchased_at: '2026-07-01',
  received_at_dor: '2026-07-05',
  checked_by_asc: false,
  signed_by_representative: 'J. Cruz',
  signed_by_branch_manager: 'R. Dela Peña',
  items: [
    { item_id: 1, unit_role: 'indoor',  product_name: 'Split Indoor',  model_code: 'AK-ID-9000', serial_number: 'SNID-111', quantity: 1, status: 'quarantined', outcome: 'scrap' },
    { item_id: 2, unit_role: 'outdoor', product_name: 'Split Outdoor', model_code: 'AK-OD-9000', serial_number: 'SNOD-222', quantity: 1, status: 'quarantined', outcome: 'restock' },
  ],
  evidence: [
    { evidence_id: 1, requirement: 'defect_photo',         photo_path: 'a.jpg', skipped_reason: null },
    { evidence_id: 2, requirement: 'model_serial_sticker', photo_path: 'b.jpg', skipped_reason: null },
    { evidence_id: 3, requirement: 'box_two_sides',        photo_path: null,    skipped_reason: 'the box was already discarded' },
    { evidence_id: 4, requirement: 'accessories_warranty', photo_path: 'c.jpg', skipped_reason: null },
  ],
});

describe('printableUnits', () => {
  test('unit_role drives the client\'s Model ID / Model OD lines, DOR printed once', () => {
    const rows = printableUnits(splitSystemReport());
    expect(rows.map((r) => r.label)).toEqual(['Model ID', 'Model OD']);
    expect(rows[0]).toMatchObject({ model: 'AK-ID-9000', serial: 'SNID-111', dor: '2026-07-05' });
    // The DOR belongs to the delivery, not to each unit.
    expect(rows[1].dor).toBeNull();
  });

  test('a lone unit prints one Model line, and the DOR lands on it', () => {
    const report = splitSystemReport();
    report.items = [{ item_id: 9, unit_role: 'single', product_name: 'Window Type', model_code: 'AK-W-100', serial_number: 'SN-9' }];
    const rows = printableUnits(report);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ label: 'Model', model: 'AK-W-100', dor: '2026-07-05' });
  });

  test('falls back to the catalogue name when no sticker was recorded', () => {
    const report = splitSystemReport();
    report.items = [{ item_id: 3, unit_role: 'single', product_name: 'Window Type', model_code: null, serial_number: null }];
    expect(printableUnits(report)[0].model).toBe('Window Type');
  });
});

describe('relatedDocsStatus', () => {
  test('all five requirements print, satisfied or skipped with the stated reason', () => {
    const docs = relatedDocsStatus(splitSystemReport());
    expect(docs).toHaveLength(5);
    expect(docs[0]).toMatchObject({ satisfied: true, photos: 1, skippedReason: null });
    expect(docs[2]).toMatchObject({ satisfied: false, skippedReason: 'the box was already discarded' });
    // Never attached, never skipped — the line is still on the paper.
    expect(docs[4]).toMatchObject({ satisfied: false, skippedReason: null, provided: false });
  });
});

describe('unitDefectText', () => {
  test('reads the reason with whichever cause field that reason filled', () => {
    expect(unitDefectText(splitSystemReport()))
      .toBe('Defective — Arrived faulty from the supplier');
    expect(unitDefectText({ reason: 'missing', suspected_cause: 'theft', last_known_location: 'Bay 3' }))
      .toBe('Missing — Taken — suspected theft — last seen: Bay 3');
  });
});

describe('buildIncidentReportHtml', () => {
  test('carries the letterhead, the title and the client\'s note', () => {
    const html = buildIncidentReportHtml(splitSystemReport());
    expect(html).toContain('LGM GLOBAL INDUSTRIES INC.');
    expect(html).toContain('Door 3, BLUZ Building, Tadeco Road, Panabo City, Davao del Norte');
    expect(html).toContain('airkingpanabo@gmail.com | (+63) 917 143 6394');
    expect(html).toContain('Panabo | Tagum | Davao');
    expect(html).toContain('INCIDENT REPORT FORM');
    expect(html).toContain('which in need approval from management');
  });

  test('prints both units, the dealer and customer blocks and the narrative', () => {
    const html = buildIncidentReportHtml(splitSystemReport());
    expect(html).toContain('Model ID');
    expect(html).toContain('AK-ID-9000');
    expect(html).toContain('SNID-111');
    expect(html).toContain('Model OD');
    expect(html).toContain('AK-OD-9000');
    expect(html).toContain('Sunrise Aircon Trading');
    expect(html).toContain('Maria Santos');
    expect(html).toContain('12 Mabini St, Tagum City');
    expect(html).toContain('Panel cracked in transit.');
    expect(html).toContain('Compressor sound, panel unusable.');
  });

  test('a skipped requirement prints its reason, not a bare "skipped"', () => {
    const html = buildIncidentReportHtml(splitSystemReport());
    expect(html).toContain('Two side of box including the serial sticker');
    expect(html).toContain('the box was already discarded');
    expect(html).toContain('Service report if checked by ASC');
    expect(html).toContain('Not provided');
  });

  test('both signature lines print, with the recorded names above them', () => {
    const html = buildIncidentReportHtml(splitSystemReport());
    expect(html).toContain('Authorized Representative &amp; Signature / Date');
    expect(html).toContain('Branch Manager Name &amp; Signature / Date');
    expect(html).toContain('J. Cruz');
    expect(html).toContain('R. Dela Peña');
  });

  test('escapes free text so a narrative cannot break the document', () => {
    const report = splitSystemReport();
    report.narrative = '<script>alert(1)</script>';
    const html = buildIncidentReportHtml(report);
    expect(html).not.toContain('<script>alert(1)</script>');
    expect(html).toContain('&lt;script&gt;');
  });

  test('an unsigned, unit-less report still prints a full blank form', () => {
    const html = buildIncidentReportHtml({ incident_number: 'INC-0001', reason: 'other' });
    expect(html).toContain('INCIDENT REPORT FORM');
    expect(html).toContain('INC-0001');
    // The ruled model line survives so it can be written on by hand.
    expect(html).toContain('<th>Model</th>');
    expect(html).toContain('Authorized Representative');
  });
});
